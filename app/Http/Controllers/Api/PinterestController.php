<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PinterestAccount;
use App\Models\PinterestBoard;
use App\Models\User;
use App\Services\Pinterest\BoardService;
use App\Services\Pinterest\PinterestApiException;
use App\Services\Pinterest\PinterestOAuthService;
use App\Support\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PinterestController extends Controller
{
    public function __construct(private readonly PinterestOAuthService $oauth) {}

    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate(['environment' => ['nullable', Rule::in(['production', 'sandbox'])]]);

        if (! $this->oauth->isConfigured()) {
            return response()->json([
                'message' => 'Pinterest is not configured on the server. Set PINTEREST_CLIENT_ID and PINTEREST_CLIENT_SECRET (see README).',
            ], 503);
        }

        return response()->json([
            'authorize_url' => $this->oauth->authorizationUrl($request->user(), $data['environment'] ?? 'production'),
        ]);
    }

    /** OAuth redirect target (web route: Pinterest redirects the browser here). */
    public function callback(Request $request): RedirectResponse
    {
        $state = $this->oauth->consumeState((string) $request->query('state'));
        if (! $state) {
            ActivityLogger::security('oauth_invalid_state', ['ip' => $request->ip()]);

            return redirect('/accounts?pinterest=error&reason='.urlencode('The authorization request expired or is invalid. Please try again.'));
        }

        // The state was issued to a specific user; if a different user is signed in, refuse.
        if ($request->user() && $request->user()->id !== $state['user_id']) {
            ActivityLogger::security('oauth_user_mismatch', ['state_user' => $state['user_id'], 'session_user' => $request->user()->id]);

            return redirect('/accounts?pinterest=error&reason='.urlencode('Signed-in user does not match the authorization request.'));
        }

        if ($error = $request->query('error')) {
            ActivityLogger::log($state['user_id'], 'pinterest_oauth_denied', 'Pinterest authorization was cancelled or denied.', 'warning', details: ['error' => $error, 'description' => $request->query('error_description')]);

            return redirect('/accounts?pinterest=error&reason='.urlencode('Authorization was cancelled.'));
        }

        try {
            $account = $this->oauth->handleCallback(User::findOrFail($state['user_id']), (string) $request->query('code'), $state['environment']);
        } catch (PinterestApiException $e) {
            ActivityLogger::log($state['user_id'], 'pinterest_oauth_failed', $e->getMessage(), 'error', details: $e->developerDetails(), httpStatus: $e->status);

            return redirect('/accounts?pinterest=error&reason='.urlencode($e->getMessage()));
        }

        try {
            app(BoardService::class)->sync($account);
        } catch (PinterestApiException) {
            // Boards can be refreshed manually later.
        }

        return redirect('/accounts?pinterest=connected');
    }

    public function accounts(Request $request): JsonResponse
    {
        $accounts = $request->user()->pinterestAccounts()->withCount('boards')->orderBy('created_at')->get();
        $prefs = $request->user()->preferences();

        return response()->json([
            'data' => $accounts,
            'configured' => $this->oauth->isConfigured(),
            'default_account_id' => $prefs->default_pinterest_account_id,
            'default_board_id' => $prefs->default_board_id,
        ]);
    }

    public function disconnect(Request $request, PinterestAccount $account): JsonResponse
    {
        Gate::authorize('delete', $account);
        $username = $account->username;
        // Removing the row destroys the encrypted tokens. Pins keep their history (FK set null).
        $account->delete();
        ActivityLogger::log($request->user()->id, 'pinterest_disconnected', "Disconnected Pinterest account @{$username}");

        return response()->json(['message' => 'Account disconnected.']);
    }

    public function refreshToken(Request $request, PinterestAccount $account): JsonResponse
    {
        Gate::authorize('update', $account);

        try {
            $this->oauth->refresh($account);
        } catch (PinterestApiException $e) {
            throw ValidationException::withMessages(['account' => $e->getMessage()]);
        }

        return response()->json(['data' => $account->fresh()]);
    }

    public function boards(Request $request, BoardService $boards): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'refresh' => ['nullable', 'boolean'],
        ]);

        $accounts = $request->user()->pinterestAccounts()
            ->when($data['account_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->get();

        $errors = [];
        if ($request->boolean('refresh')) {
            foreach ($accounts as $account) {
                try {
                    $boards->sync($account);
                } catch (PinterestApiException $e) {
                    ActivityLogger::log($request->user()->id, 'boards_sync_failed', $e->getMessage(), 'warning', details: $e->developerDetails(), httpStatus: $e->status, accountId: $account->id);
                    $errors[] = '@'.$account->username.': '.$e->getMessage();
                }
            }
        }

        $list = PinterestBoard::whereIn('pinterest_account_id', $accounts->pluck('id'))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $list, 'errors' => $errors]);
    }

    public function createBoard(Request $request, BoardService $boards): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', Rule::exists('pinterest_accounts', 'id')->where('user_id', $request->user()->id)],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:500'],
            'privacy' => ['nullable', Rule::in(['PUBLIC', 'SECRET'])],
        ]);
        $account = PinterestAccount::findOrFail($data['account_id']);

        try {
            $board = $boards->create($account, $data['name'], $data['description'] ?? null, $data['privacy'] ?? 'PUBLIC');
        } catch (PinterestApiException $e) {
            ActivityLogger::log($request->user()->id, 'board_create_failed', $e->getMessage(), 'error', details: $e->developerDetails(), httpStatus: $e->status, accountId: $account->id);
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return response()->json(['data' => $board], 201);
    }

    public function setDefault(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['nullable', Rule::exists('pinterest_accounts', 'id')->where('user_id', $request->user()->id)],
            'board_id' => ['nullable', 'string', 'max:64'],
        ]);
        $request->user()->preferences()->update([
            'default_pinterest_account_id' => $data['account_id'] ?? null,
            'default_board_id' => $data['board_id'] ?? null,
        ]);

        return response()->json(['message' => 'Default board saved.']);
    }
}
