<?php

namespace CraigPaul\Mail;

use function array_filter;
use function array_map;
use function array_merge;
use Illuminate\Http\Client\Factory as Http;
use function implode;
use function in_array;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\RawMessage;

class PostmarkTransport implements TransportInterface
{
    protected const BYPASS_HEADERS = [
        'from',
        'to',
        'cc',
        'bcc',
        'subject',
        'content-type',
        'sender',
        'reply-to',
    ];

    public function __construct(
        protected Http $http,
        protected ?string $messageStreamId,
        protected string $token,
    ) {
    }

    public function send(RawMessage $message, Envelope $envelope = null): ?SentMessage
    {
        $envelope = $envelope ?? Envelope::create($message);

        $sentMessage = new SentMessage($message, $envelope);

        $email = MessageConverter::toEmail($sentMessage->getOriginalMessage());

        $response = $this->http
            ->acceptJson()
            ->withHeaders([
                'X-Postmark-Server-Token' => $this->token,
            ])
            ->post('https://api.postmarkapp.com/email', $this->getPayload($email, $envelope));

        if ($response->ok()) {
            $sentMessage->setMessageId($response->json('MessageID'));

            return $sentMessage;
        }

        throw new PostmarkTransportException(
            $response->json('Message'),
            $response->json('ErrorCode'),
            $response->toException(),
        );
    }

    protected function getAttachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();

            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
            $disposition = $headers->getHeaderBody('Content-Disposition');

            $attributes = [
                'Name' => $filename,
                'Content' => $attachment->bodyToString(),
                'ContentType' => $headers->get('Content-Type')->getBody(),
            ];

            if ($disposition === 'inline') {
                $attributes['ContentID'] = 'cid:'.$filename;
            }

            $attachments[] = $attributes;
        }

        return $attachments;
    }

    protected function getPayload(Email $email, Envelope $envelope): array
    {
        $payload = [
            'From' => $envelope->getSender()->toString(),
            'To' => $this->stringifyAddresses($this->getRecipients($email, $envelope)),
            'Cc' => $this->stringifyAddresses($email->getCc()),
            'Bcc' => $this->stringifyAddresses($email->getBcc()),
            'Subject' => $email->getSubject(),
            'HtmlBody' => $email->getHtmlBody(),
            'TextBody' => $email->getTextBody(),
            'ReplyTo' => $this->stringifyAddresses($email->getReplyTo()),
            'Attachments' => $this->getAttachments($email),
            'MessageStream' => $this->messageStreamId ?? '',
        ];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (in_array($name, self::BYPASS_HEADERS, true)) {
                continue;
            }

            if ($header instanceof TagHeader) {
                $payload['Tag'] = $header->getValue();

                continue;
            }

            if ($header instanceof MetadataHeader) {
                $payload['Metadata'][$header->getKey()] = $header->getValue();

                continue;
            }

            $payload['Headers'][] = [
                'Name' => $name,
                'Value' => $header->getBodyAsString(),
            ];
        }

        return array_filter($payload);
    }

    protected function getRecipients(Email $email, Envelope $envelope): array
    {
        $copies = array_merge($email->getCc(), $email->getBcc());

        return array_filter($envelope->getRecipients(), function (Address $address) use ($copies) {
            return in_array($address, $copies, true) === false;
        });
    }

    protected function stringifyAddresses(array $addresses): string
    {
        return implode(',', array_map(fn (Address $address) => $address->toString(), $addresses));
    }

    public function __toString(): string
    {
        return 'postmark';
    }
}
