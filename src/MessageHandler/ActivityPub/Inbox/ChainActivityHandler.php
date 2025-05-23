<?php

declare(strict_types=1);

namespace App\MessageHandler\ActivityPub\Inbox;

use App\Entity\Entry;
use App\Entity\EntryComment;
use App\Entity\Post;
use App\Entity\PostComment;
use App\Exception\EntityNotFoundException;
use App\Exception\InstanceBannedException;
use App\Exception\TagBannedException;
use App\Exception\UserBannedException;
use App\Exception\UserDeletedException;
use App\Message\ActivityPub\Inbox\AnnounceMessage;
use App\Message\ActivityPub\Inbox\ChainActivityMessage;
use App\Message\ActivityPub\Inbox\DislikeMessage;
use App\Message\ActivityPub\Inbox\LikeMessage;
use App\Message\Contracts\MessageInterface;
use App\MessageHandler\MbinMessageHandler;
use App\Repository\ApActivityRepository;
use App\Service\ActivityPub\ApHttpClientInterface;
use App\Service\ActivityPub\Note;
use App\Service\ActivityPub\Page;
use App\Service\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class ChainActivityHandler extends MbinMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
        private readonly LoggerInterface $logger,
        private readonly ApHttpClientInterface $client,
        private readonly MessageBusInterface $bus,
        private readonly ApActivityRepository $repository,
        private readonly Note $note,
        private readonly Page $page,
        private readonly SettingsManager $settingsManager,
    ) {
        parent::__construct($this->entityManager, $this->kernel);
    }

    public function __invoke(ChainActivityMessage $message): void
    {
        $this->workWrapper($message);
    }

    public function doWork(MessageInterface $message): void
    {
        if (!($message instanceof ChainActivityMessage)) {
            throw new \LogicException();
        }
        $this->logger->debug('Got chain activity message: {m}', ['m' => $message]);
        if (!$message->chain || 0 === \sizeof($message->chain)) {
            return;
        }
        $validObjectTypes = ['Page', 'Note', 'Article', 'Question', 'Video'];
        $object = $message->chain[0];
        if (!\in_array($object['type'], $validObjectTypes)) {
            $this->logger->error('[ChainActivityHandler::doWork] Cannot get the dependencies of the object, its type {t} is not one we can handle. {m]', ['t' => $object['type'], 'm' => $message]);

            return;
        }
        try {
            $entity = $this->retrieveObject($object['id']);
        } catch (InstanceBannedException) {
            $this->logger->info('[ChainActivityHandler::doWork] The instance is banned, url: {url}', ['url' => $object['id']]);

            return;
        }

        if (!$entity) {
            $this->logger->error('[ChainActivityHandler::doWork] Could not retrieve all the dependencies of {o}', ['o' => $object['id']]);

            return;
        }

        if ($message->announce) {
            $this->bus->dispatch(new AnnounceMessage($message->announce));
        }

        if ($message->like) {
            $this->bus->dispatch(new LikeMessage($message->like));
        }

        if ($message->dislike) {
            $this->bus->dispatch(new DislikeMessage($message->dislike));
        }
    }

    /**
     * @throws \Exception if there was an unexpected exception
     */
    private function retrieveObject(string $apUrl): Entry|EntryComment|Post|PostComment|null
    {
        if ($this->settingsManager->isBannedInstance($apUrl)) {
            throw new InstanceBannedException();
        }
        try {
            $object = $this->client->getActivityObject($apUrl);
            if (!$object) {
                $this->logger->warning('[ChainActivityHandler::retrieveObject] Got an empty object for {url}', ['url' => $apUrl]);

                return null;
            }
            if (!\is_array($object)) {
                $this->logger->warning("[ChainActivityHandler::retrieveObject] Didn't get an array for {url}. Got '{val}' instead, exiting", ['url' => $apUrl, 'val' => $object]);

                return null;
            }

            if (\array_key_exists('inReplyTo', $object) && null !== $object['inReplyTo']) {
                $parentUrl = \is_string($object['inReplyTo']) ? $object['inReplyTo'] : $object['inReplyTo']['id'];
                $meta = $this->repository->findByObjectId($parentUrl);
                if (!$meta) {
                    $this->retrieveObject($parentUrl);
                }
                $meta = $this->repository->findByObjectId($parentUrl);
                if (!$meta) {
                    $this->logger->warning('[ChainActivityHandler::retrieveObject] Fetching the parent object ({parent}) did not work for {url}, aborting', ['parent' => $parentUrl, 'url' => $apUrl]);

                    return null;
                }
            }

            switch ($object['type']) {
                case 'Question':
                case 'Note':
                    $this->logger->debug('[ChainActivityHandler::retrieveObject] Creating note {o}', ['o' => $object]);

                    return $this->note->create($object);
                case 'Page':
                case 'Article':
                case 'Video':
                    $this->logger->debug('[ChainActivityHandler::retrieveObject] Creating page {o}', ['o' => $object]);

                    return $this->page->create($object);
                default:
                    $this->logger->warning('[ChainActivityHandler::retrieveObject] Could not create an object from type {t} on {url}: {o}', ['t' => $object['type'], 'url' => $apUrl, 'o' => $object]);
            }
        } catch (UserBannedException) {
            $this->logger->info('[ChainActivityHandler::retrieveObject] The user is banned, url: {url}', ['url' => $apUrl]);
        } catch (UserDeletedException) {
            $this->logger->info('[ChainActivityHandler::retrieveObject] The user is deleted, url: {url}', ['url' => $apUrl]);
        } catch (TagBannedException) {
            $this->logger->info('[ChainActivityHandler::retrieveObject] One of the used tags is banned, url: {url}', ['url' => $apUrl]);
        } catch (InstanceBannedException) {
            $this->logger->info('[ChainActivityHandler::retrieveObject] The instance is banned, url: {url}', ['url' => $apUrl]);
        } catch (EntityNotFoundException $e) {
            $this->logger->error('[ChainActivityHandler::retrieveObject] There was an exception while getting {url}: {ex} - {m}. {o}', ['url' => $apUrl, 'ex' => \get_class($e), 'm' => $e->getMessage(), 'o' => $e]);
        }

        return null;
    }
}
