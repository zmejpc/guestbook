<?php

namespace App\MessageHandler;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use App\Notification\CommentReviewNotification;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\CommentRepository;
use App\Message\CommentMessage;
use Psr\Log\LoggerInterface;
use App\ImageOptimizer;
use App\SpamChecker;

#[AsMessageHandler]
class CommentMessageHandler
{
    public function __construct(
        #[Autowire('%photo_dir%')] private string $photo_dir,
        private WorkflowInterface $commentStateMachine,
        private EntityManagerInterface $entityManager,
        private CommentRepository $commentRepository,
        private ?LoggerInterface $logger = null,
        private ImageOptimizer $imageOptimizer,
        private NotifierInterface $notifier,
        private SpamChecker $spamChecker,
        private MessageBusInterface $bus,
    ) {}

    public function __invoke(CommentMessage $message)
    {
        $comment = $this->commentRepository->find($message->getId());
        if (!$comment) {
            return;
        }

        if ($this->commentStateMachine->can($comment, 'accept')) {

            $score = $this->spamChecker->getSpamScore($comment, $message->getContext());
            $transition = match ($score) {
                2 => 'reject_spam',
                1 => 'might_be_spam',
                default => 'accept',
            };
            $this->commentStateMachine->apply($comment, $transition);
            $this->entityManager->flush();

            $this->bus->dispatch($message);

        } elseif ($this->commentStateMachine->can($comment, 'publish') || $this->commentStateMachine->can($comment, 'publish_ham')) {
            
            $this->notifier->send(new CommentReviewNotification($comment), ...$this->notifier->getAdminRecipients());

         } elseif ($this->commentStateMachine->can($comment, 'optimize')) {
            if ($comment->getPhotoFilename()) {
                $this->imageOptimizer->resize($this->photo_dir.'/'.$comment->getPhotoFilename());
            }
            $this->commentStateMachine->apply($comment, 'optimize');
            $this->entityManager->flush();
         } elseif ($this->logger) {
            $this->logger->debug('Dropping comment message', ['comment' => $comment->getId(), 'state' => $comment->getState()]);
         }
    }
}