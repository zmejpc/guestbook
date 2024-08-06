<?php

namespace App\MessageHandler;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Bridge\Twig\Mime\NotificationEmail;
use Symfony\Component\Mailer\MailerInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\CommentRepository;
use App\Message\CommentMessage;
use Psr\Log\LoggerInterface;
use App\SpamChecker;

#[AsMessageHandler]
class CommentMessageHandler
{
    public function __construct(
        #[Autowire('%admin_email%')] private string $admin_email,
        private WorkflowInterface $commentStateMachine,
        private EntityManagerInterface $entityManager,
        private CommentRepository $commentRepository,
        private ?LoggerInterface $logger = null,
        private SpamChecker $spamChecker,
        private MessageBusInterface $bus,
        private MailerInterface $mailer,
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
            
            $this->mailer->send((new NotificationEmail())
                ->subject('New comment posted')
                ->htmlTemplate('emails/comment_notification.html.twig')
                ->from($this->admin_email)
                ->to($this->admin_email)
                ->context(['comment' => $comment])
            );

         } elseif ($this->logger) {
            $this->logger->debug('Dropping comment message', ['comment' => $comment->getId(), 'state' => $comment->getState()]);
         }
    }
}