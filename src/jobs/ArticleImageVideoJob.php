<?php

namespace arjanbrinkman\craftimageenhancer\jobs;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use arjanbrinkman\craftimageenhancer\services\AiVideoGenerationService;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

class ArticleImageVideoJob extends BaseJob implements RetryableJobInterface
{
	public int $assetId;
	public ?int $userId = null;
	public string $token;
	public string $videoPrompt = '';
	public string $videoProvider = AiVideoGenerationService::PROVIDER_GOOGLE;
	public string $videoModel = AiVideoGenerationService::GOOGLE_MODEL_GEMINI_OMNI_FLASH;

	public function execute($queue): void
	{
		$videoPath = null;
		$this->updateStatus('running', 0.05, 'Loading source image');
		$this->setProgress($queue, 0.05, 'Loading source image');

		try {
			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$asset = Craft::$app->assets->getAssetById($this->assetId);
			if (!$this->isSupportedImageAsset($asset)) {
				throw new \RuntimeException('Asset not found or unsupported.');
			}

			$localPath = $this->getFullAssetPath($asset);
			if (!$localPath || !file_exists($localPath)) {
				throw new \RuntimeException('Could not find the source image file.');
			}

			$service = ImageEnhancer::getInstance()->aiVideoGeneration;
			$videoPath = $service->createVideoToTempFile(
				Craft::createGuzzleClient(),
				ImageEnhancer::getInstance()->getSettings(),
				$asset,
				$localPath,
				$this->videoPrompt,
				$this->videoProvider,
				$this->videoModel,
				function(float $progress, string $label) use ($queue): void {
					if ($this->isCanceled()) {
						return;
					}

					$this->updateStatus('running', $progress, $label);
					$this->setProgress($queue, $progress, $label);
				},
				fn(): bool => $this->isCanceled(),
			);

			if ($this->isCanceled()) {
				$service->deleteVideo($videoPath);
				$this->finishCanceled($queue);
				return;
			}

			$this->updateStatus('complete', 1, 'Video ready to download', [
				'videoPath' => $videoPath,
				'videoFilename' => $service->getDownloadFilename($asset),
				'videoProvider' => $this->videoProvider,
				'videoModel' => $this->videoModel,
			]);
			$this->setProgress($queue, 1, 'Video ready to download');
		} catch (\Throwable $e) {
			if ($videoPath) {
				ImageEnhancer::getInstance()->aiVideoGeneration->deleteVideo($videoPath);
			}

			if ($this->isCanceled()) {
				$this->finishCanceled($queue);
				return;
			}

			$this->updateStatus('failed', 1, 'Video generation failed', [
				'message' => $e->getMessage(),
			]);
			Craft::error('ImageEnhancer: Article image video queue job failed: ' . $e->getMessage(), __METHOD__);
			throw $e;
		}
	}

	public function getTtr(): int
	{
		return 1200;
	}

	public function canRetry($attempt, $error): bool
	{
		return false;
	}

	private function isSupportedImageAsset(?Asset $asset): bool
	{
		return $asset instanceof Asset &&
			$asset->kind === 'image' &&
			in_array($asset->mimeType, ['image/jpeg', 'image/jpg', 'image/png'], true);
	}

	private function getFullAssetPath(Asset $asset): ?string
	{
		if (!$this->isSupportedImageAsset($asset)) {
			return null;
		}

		$fsPath = Craft::getAlias($asset->getFs()->path);
		if (!$fsPath) {
			return null;
		}

		return $fsPath . DIRECTORY_SEPARATOR . $asset->folderPath . $asset->filename;
	}

	private function updateStatus(string $status, float $progress, string $progressLabel, array $extra = []): void
	{
		if ($status !== 'canceled' && $this->isCanceled()) {
			return;
		}

		$statusPayload = array_merge([
			'status' => $status,
			'assetId' => $this->assetId,
			'token' => $this->token,
			'operation' => 'createVideo',
			'videoProvider' => $this->videoProvider,
			'videoModel' => $this->videoModel,
			'progress' => $progress,
			'progressLabel' => $progressLabel,
		], $extra);

		Craft::$app->getCache()->set($this->getStatusCacheKey(), $statusPayload, 3600);
		Craft::$app->getCache()->set($this->getAssetStatusCacheKey(), $statusPayload, 3600);
	}

	private function isCanceled(): bool
	{
		$status = Craft::$app->getCache()->get($this->getStatusCacheKey());

		return is_array($status) && ($status['status'] ?? null) === 'canceled';
	}

	private function finishCanceled($queue): void
	{
		$this->updateStatus('canceled', 1, 'Canceled');
		$this->setProgress($queue, 1, 'Canceled');
	}

	private function getStatusCacheKey(): string
	{
		return 'image-enhancer:article-image-enhancement:' . $this->token;
	}

	private function getAssetStatusCacheKey(): string
	{
		return 'image-enhancer:article-image-enhancement-asset:' . $this->assetId;
	}

	private function getRelatedEntryForAsset(): ?Entry
	{
		$sourceId = (new Query())
			->select(['sourceId'])
			->from(Table::RELATIONS)
			->where(['targetId' => $this->assetId])
			->scalar();

		if (!$sourceId) {
			return null;
		}

		$element = Craft::$app->elements->getElementById((int) $sourceId, null, '*');
		if ($element instanceof Entry) {
			return $element;
		}

		$ownerId = $element->ownerId ?? null;
		if (!$ownerId) {
			return null;
		}

		$owner = Craft::$app->elements->getElementById((int) $ownerId, Entry::class, '*');

		return $owner instanceof Entry ? $owner : null;
	}

	private function truncateTitle(string $title, int $limit = 25): string
	{
		$title = trim($title);
		$length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
		if ($length <= $limit) {
			return $title;
		}

		$slice = function_exists('mb_substr')
			? mb_substr($title, 0, max(0, $limit - 3))
			: substr($title, 0, max(0, $limit - 3));

		return rtrim($slice) . '...';
	}

	protected function defaultDescription(): string
	{
		$title = $this->getRelatedEntryForAsset()?->title ?? null;
		$title = $title ? $this->truncateTitle($title) : null;

		return $title ? 'Create image video: ' . $title : 'Create image video';
	}
}
