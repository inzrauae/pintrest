<?php

namespace App\Services\Pinterest;

use App\Models\PinterestAccount;
use App\Models\PinterestBoard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BoardService
{
    private const MAX_PAGES = 20;

    public function __construct(private readonly PinterestClient $client) {}

    /** Pulls all boards from Pinterest (paginated with bookmarks) and refreshes the cache. */
    public function sync(PinterestAccount $account): Collection
    {
        $items = [];
        $bookmark = null;
        $page = 0;

        do {
            $response = $this->client->get($account, 'boards', array_filter([
                'page_size' => 100,
                'bookmark' => $bookmark,
            ]));
            array_push($items, ...($response['items'] ?? []));
            $bookmark = $response['bookmark'] ?? null;
        } while ($bookmark && ++$page < self::MAX_PAGES);

        DB::transaction(function () use ($account, $items) {
            $ids = [];
            foreach ($items as $board) {
                $ids[] = $board['id'];
                PinterestBoard::updateOrCreate(
                    ['pinterest_account_id' => $account->id, 'board_id' => $board['id']],
                    [
                        'name' => mb_substr($board['name'] ?? 'Untitled', 0, 255),
                        'description' => $board['description'] ?? null,
                        'privacy' => $board['privacy'] ?? null,
                        'pin_count' => (int) ($board['pin_count'] ?? 0),
                        'image_url' => $board['media']['image_cover_url'] ?? null,
                        'synced_at' => now(),
                    ],
                );
            }
            $account->boards()->whereNotIn('board_id', $ids)->delete();
        });

        return $account->boards()->orderBy('name')->get();
    }

    public function create(PinterestAccount $account, string $name, ?string $description = null, string $privacy = 'PUBLIC'): PinterestBoard
    {
        $board = $this->client->post($account, 'boards', array_filter([
            'name' => $name,
            'description' => $description,
            'privacy' => $privacy,
        ]));

        return PinterestBoard::updateOrCreate(
            ['pinterest_account_id' => $account->id, 'board_id' => $board['id']],
            ['name' => $board['name'] ?? $name, 'description' => $description, 'privacy' => $privacy, 'synced_at' => now()],
        );
    }
}
