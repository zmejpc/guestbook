<?php

namespace App\Notification;

use Symfony\Component\Notifier\Notification\EmailNotificationInterface;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Message\EmailMessage;
use App\Entity\Comment;

class CommentReviewNotification extends Notification implements EmailNotificationInterface
{
    public function __construct(
        private Comment $comment,
    ) {
        parent::__construct('New comment posted');
    }

    public function asEmailMessage(EmailRecipientInterface $recipient, string $transport = null): ?EmailMessage
    {
        $message = EmailMessage::fromNotification($this, $recipient, $transport);
        $message->getMessage()
            ->htmlTemplate('emails/comment_notification.html.twig')
            ->context(['comment' => $this->comment])
        ;

        return $message;
    }
}