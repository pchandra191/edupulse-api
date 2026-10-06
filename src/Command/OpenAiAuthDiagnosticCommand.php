<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:openai:auth-diagnostic',
    description: 'Diagnose OpenAI API key authentication by calling /v1/me endpoint',
)]
class OpenAiAuthDiagnosticCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('This command validates the OpenAI API key by calling the /v1/me endpoint. It logs diagnostic info without exposing the full key.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('OpenAI API Key Authentication Diagnostic');

        // Diagnostic: log auth configuration
        $apiKeyConfigured = !empty($this->apiKey);
        $apiKeyLength = $apiKeyConfigured ? strlen($this->apiKey) : 0;
        $apiKeyPrefix = $apiKeyConfigured ? substr($this->apiKey, 0, 7) : 'N/A';
        $apiKeySuffix = $apiKeyConfigured ? substr($this->apiKey, -4) : 'N/A';
        $endpoint = 'https://api.openai.com/v1/me';

        $io->note(sprintf('[OPENAI-AUTH-DIAGNOSTIC] apiKeyConfigured=%s apiKeyLength=%d apiKeyPrefix=%s apiKeySuffix=%s endpoint=%s',
            $apiKeyConfigured ? 'true' : 'false',
            $apiKeyLength,
            $apiKeyPrefix,
            $apiKeySuffix,
            $endpoint
        ));

        if (!$apiKeyConfigured) {
            $io->error('OpenAI API key is not configured!');
            return Command::FAILURE;
        }

        // Make the authenticated request to /v1/me
        $io->note('Calling GET ' . $endpoint . ' with Authorization: Bearer <redacted>');

        try {
            $response = $this->httpClient->request('GET', 'https://api.openai.com/v1/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($statusCode === 200) {
                $data = json_decode($content, true);
                $io->success(sprintf('[OPENAI-AUTH-DIAGNOSTIC] HTTP %d - Key is VALID', $statusCode));
                $io->note('OpenAI Response: ' . json_encode($data));
            } else {
                $errorData = json_decode($content, true);
                $errorCode = $errorData['error']['code'] ?? 'unknown';
                $errorType = $errorData['error']['type'] ?? 'unknown';
                $errorMessage = $errorData['error']['message'] ?? $content;

                $io->error(sprintf('[OPENAI-AUTH-DIAGNOSTIC] HTTP %d - Key is INVALID', $statusCode));
                $io->error('OpenAI Error: ' . json_encode([
                    'code' => $errorCode,
                    'type' => $errorType,
                    'message' => $errorMessage,
                ]));

                return Command::FAILURE;
            }
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            $io->error('Network error: ' . $e->getMessage());
            return Command::FAILURE;
        } catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
            $io->error('Client error: ' . $e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Unexpected error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}