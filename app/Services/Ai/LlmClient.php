<?php

namespace App\Services\Ai;

use App\Models\AiProviderSetting;

interface LlmClient
{
    /**
     * Ask the model for a JSON object that matches $schema.
     *
     * @param  array  $context  Structured source data (used by offline providers; ignored by remote ones).
     * @return array Decoded JSON object (still unvalidated).
     *
     * @throws AiException
     */
    public function generateJson(string $system, string $user, array $schema, array $context, AiProviderSetting $settings): array;
}
