<?php

namespace arjanbrinkman\craftimageenhancer\services;

use arjanbrinkman\craftimageenhancer\models\Settings;
use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use GuzzleHttp\ClientInterface;

class AiVideoGenerationService extends Component
{
	public const MODEL = 'gemini-omni-1.1-flash';
	public const MAX_PROMPT_LENGTH = 4000;

	private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';
	private const MAX_FILE_POLLS = 120;
	private const FILE_POLL_INTERVAL_MICROSECONDS = 5000000;
	private const VIDEO_RETENTION_SECONDS = 86400;

	public function createVideoToTempFile(
		ClientInterface $client,
		Settings $settings,
		Asset $asset,
		string $localPath,
		string $instructions = '',
		?callable $onProgress = null,
		?callable $isCanceled = null,
	): string {
		$apiKey = $settings->getResolvedGoogleAiApiKey();
		if ($apiKey === '') {
			throw new \RuntimeException('Google AI API key is missing.');
		}

		$imageBytes = file_get_contents($localPath);
		if ($imageBytes === false) {
			throw new \RuntimeException('Could not read the source image.');
		}

		$this->cleanupExpiredVideos();
		$this->throwIfCanceled($isCanceled);
		$this->reportProgress($onProgress, 0.2, 'Generating video with Gemini Omni Flash');

		$response = $client->post(self::API_BASE_URL . '/interactions', [
			'headers' => [
				'x-goog-api-key' => $apiKey,
				'Content-Type' => 'application/json',
			],
			'json' => [
				'model' => self::MODEL,
				'input' => [
					[
						'type' => 'image',
						'data' => base64_encode($imageBytes),
						'mime_type' => $asset->mimeType ?: 'image/jpeg',
					],
					[
						'type' => 'text',
						'text' => $this->buildPrompt($instructions),
					],
				],
				'response_format' => [
					'type' => 'video',
					'aspect_ratio' => $this->resolveAspectRatio($asset, $localPath),
					'resolution' => '720p',
					'delivery' => 'uri',
				],
			],
			'connect_timeout' => 30,
			'timeout' => 900,
		]);

		$this->throwIfCanceled($isCanceled);
		$data = json_decode((string) $response->getBody(), true);
		if (!is_array($data)) {
			throw new \RuntimeException('Google returned an invalid video response.');
		}

		$output = $this->extractVideoOutput($data);
		if (!empty($output['data'])) {
			$this->reportProgress($onProgress, 0.9, 'Saving generated video');

			return $this->writeBase64Video((string) $output['data']);
		}

		$uri = (string) ($output['uri'] ?? '');
		if ($uri === '') {
			$message = $data['error']['message'] ?? 'Google returned no generated video.';
			throw new \RuntimeException((string) $message);
		}

		$fileId = $this->extractFileId($uri);
		if ($fileId === null) {
			throw new \RuntimeException('Google returned an invalid video file reference.');
		}
		$this->waitUntilFileIsActive($client, $apiKey, $fileId, $onProgress, $isCanceled);

		$this->throwIfCanceled($isCanceled);
		$this->reportProgress($onProgress, 0.9, 'Downloading generated video');

		return $this->downloadVideo($client, $apiKey, $fileId);
	}

	public function getDownloadFilename(Asset $asset): string
	{
		$baseName = pathinfo($asset->filename, PATHINFO_FILENAME);
		$baseName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseName) ?: 'image';
		$baseName = trim($baseName, '-.') ?: 'image';

		return $baseName . '-video.mp4';
	}

	public function isManagedVideoPath(?string $path): bool
	{
		if (!$path || !is_file($path)) {
			return false;
		}

		$directory = realpath($this->getVideoDirectory());
		$realPath = realpath($path);

		return $directory !== false &&
			$realPath !== false &&
			str_starts_with($realPath, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
	}

	public function deleteVideo(?string $path): void
	{
		if ($this->isManagedVideoPath($path)) {
			@unlink((string) $path);
		}
	}

	public function cleanupExpiredVideos(): void
	{
		$cutoff = time() - self::VIDEO_RETENTION_SECONDS;
		foreach (glob($this->getVideoDirectory() . DIRECTORY_SEPARATOR . '*.mp4') ?: [] as $path) {
			if (is_file($path) && (filemtime($path) ?: 0) < $cutoff) {
				@unlink($path);
			}
		}
	}

	private function buildPrompt(string $instructions): string
	{
		$instructions = trim($instructions);
		$customInstructions = $instructions !== ''
			? $instructions
			: 'Use subtle, natural subject and environmental motion with a gentle camera movement.';

		return '<FIRST_FRAME> Create a short, realistic video from this source image in one continuous shot. ' .
			'Unless the video instructions explicitly request a change, keep the people, identity, appearance, clothing, objects, setting, lighting, and visual style consistent with the image. ' .
			'Do not add captions, logos, watermarks, unrelated people, or unrelated objects. ' .
			'Video instructions: ' . $customInstructions;
	}

	private function resolveAspectRatio(Asset $asset, string $localPath): string
	{
		$width = (int) ($asset->width ?? 0);
		$height = (int) ($asset->height ?? 0);
		if (!$width || !$height) {
			[$width, $height] = getimagesize($localPath) ?: [16, 9];
		}

		return $height > $width ? '9:16' : '16:9';
	}

	private function extractVideoOutput(array $data): array
	{
		foreach (['output_video', 'outputVideo'] as $key) {
			if (isset($data[$key]) && is_array($data[$key])) {
				return $data[$key];
			}
		}

		foreach ($data['steps'] ?? [] as $step) {
			foreach ($step['content'] ?? [] as $content) {
				if (($content['type'] ?? null) === 'video') {
					return is_array($content) ? $content : [];
				}
			}
		}

		return [];
	}

	private function waitUntilFileIsActive(
		ClientInterface $client,
		string $apiKey,
		string $fileId,
		?callable $onProgress,
		?callable $isCanceled,
	): void {
		for ($attempt = 0; $attempt < self::MAX_FILE_POLLS; $attempt++) {
			$this->throwIfCanceled($isCanceled);
			$response = $client->get(self::API_BASE_URL . '/files/' . rawurlencode($fileId), [
				'headers' => ['x-goog-api-key' => $apiKey],
				'connect_timeout' => 15,
				'timeout' => 60,
			]);
			$data = json_decode((string) $response->getBody(), true);
			if (!is_array($data)) {
				throw new \RuntimeException('Google returned an invalid video file status.');
			}
			$state = strtoupper((string) ($data['state']['name'] ?? $data['state'] ?? ''));

			if ($state === 'ACTIVE') {
				return;
			}
			if ($state === 'FAILED') {
				throw new \RuntimeException((string) ($data['error']['message'] ?? 'Google video processing failed.'));
			}

			$progress = min(0.88, 0.45 + (($attempt + 1) * 0.004));
			$this->reportProgress($onProgress, $progress, 'Preparing generated video');
			usleep(self::FILE_POLL_INTERVAL_MICROSECONDS);
		}

		throw new \RuntimeException('Google video processing timed out.');
	}

	private function downloadVideo(ClientInterface $client, string $apiKey, string $fileId): string
	{
		$path = $this->createVideoPath();
		$downloadUrl = self::API_BASE_URL . '/files/' . rawurlencode($fileId) . ':download';

		try {
			$client->get($downloadUrl, [
				'query' => [
					'alt' => 'media',
					'key' => $apiKey,
				],
				'allow_redirects' => true,
				'connect_timeout' => 30,
				'timeout' => 300,
				'sink' => $path,
			]);
			if (!is_file($path) || filesize($path) === 0) {
				throw new \RuntimeException('The generated video download was empty.');
			}
			$this->assertValidMp4($path);

			return $path;
		} catch (\Throwable $e) {
			@unlink($path);
			throw new \RuntimeException('Could not download the generated video from Google.', 0, $e);
		}
	}

	private function writeBase64Video(string $videoData): string
	{
		if (str_contains($videoData, ',')) {
			[, $videoData] = explode(',', $videoData, 2);
		}

		$decodedVideo = base64_decode($videoData, true);
		if ($decodedVideo === false || $decodedVideo === '') {
			throw new \RuntimeException('Google returned invalid base64 video data.');
		}

		$path = $this->createVideoPath();
		if (file_put_contents($path, $decodedVideo) === false) {
			@unlink($path);
			throw new \RuntimeException('Could not save the generated video.');
		}

		try {
			$this->assertValidMp4($path);
		} catch (\Throwable $e) {
			@unlink($path);
			throw $e;
		}

		return $path;
	}

	private function assertValidMp4(string $path): void
	{
		$handle = fopen($path, 'rb');
		$header = $handle !== false ? fread($handle, 12) : false;
		if (is_resource($handle)) {
			fclose($handle);
		}

		if (!is_string($header) || strlen($header) < 12 || substr($header, 4, 4) !== 'ftyp') {
			throw new \RuntimeException('Google returned an invalid MP4 video.');
		}
	}

	private function extractFileId(string $uri): ?string
	{
		return preg_match('#(?:^|/)files/([^/:?]+)#', $uri, $matches) === 1
			? $matches[1]
			: null;
	}

	private function createVideoPath(): string
	{
		return $this->getVideoDirectory() . DIRECTORY_SEPARATOR . 'video-' . bin2hex(random_bytes(16)) . '.mp4';
	}

	private function getVideoDirectory(): string
	{
		$directory = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'image-enhancer-videos';
		FileHelper::createDirectory($directory);

		return $directory;
	}

	private function throwIfCanceled(?callable $isCanceled): void
	{
		if ($isCanceled && $isCanceled()) {
			throw new \RuntimeException('Video generation was canceled.');
		}
	}

	private function reportProgress(?callable $onProgress, float $progress, string $label): void
	{
		if ($onProgress) {
			$onProgress($progress, $label);
		}
	}
}
