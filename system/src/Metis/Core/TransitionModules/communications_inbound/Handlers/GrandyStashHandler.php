<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound\Handlers;

use Metis\Modules\CommunicationsInbound\Contracts\MessageHandlerInterface;
use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;
use Metis\Modules\CommunicationsInbound\ValueObjects\ParseResult;

final class GrandyStashHandler implements MessageHandlerInterface {
    public function key(): string {
        return 'grandys_stash';
    }

    public function handle( array $message_row, NormalizedInboundMessage $message, ParseResult $result ): array {
        $ticket_code = strtoupper( trim( (string) ( $result->metadata()['ticket_code'] ?? '' ) ) );
        $ticket_id = (int) ( $result->metadata()['ticket_id'] ?? 0 );
        if ( $ticket_code === '' || ! class_exists( '\Metis\Modules\GrandyStash\GrandyStashRepository' ) ) {
            return [
                'handled'  => false,
                'status'   => 'unknown',
                'metadata' => [ 'reason' => 'Grandy\'s Stash ticket code was not resolvable.' ],
            ];
        }

        $ticket = $ticket_id > 0
            ? \Metis\Modules\GrandyStash\GrandyStashRepository::getTicket( $ticket_id )
            : \Metis\Modules\GrandyStash\GrandyStashRepository::findTicketByCode( $ticket_code );
        if ( ! is_array( $ticket ) || (int) ( $ticket['id'] ?? 0 ) < 1 ) {
            return [
                'handled'  => false,
                'status'   => 'unknown',
                'metadata' => [
                    'reason'      => 'Grandy\'s Stash ticket was not found.',
                    'ticket_code' => $ticket_code,
                ],
            ];
        }

        $stored = \Metis\Modules\GrandyStash\GrandyStashRepository::recordInboundMessage(
            (int) $ticket['id'],
            $message_row,
            $message->all(),
            (strtolower( trim( \metis_email_clean( (string) ( $message->all()['canonical_sender_email'] ?? '' ) ) ) ) === strtolower( trim( \metis_email_clean( (string) ( \Metis\Modules\GrandyStash\GrandyStashRepository::ticketOwnerEmail( (int) $ticket['id'] ) ) ) ) )) ? 'outbound' : 'inbound',
            (strtolower( trim( \metis_email_clean( (string) ( $message->all()['canonical_sender_email'] ?? '' ) ) ) ) === strtolower( trim( \metis_email_clean( (string) ( \Metis\Modules\GrandyStash\GrandyStashRepository::ticketOwnerEmail( (int) $ticket['id'] ) ) ) ) )) ? strtolower( trim( \metis_email_clean( (string) ( $ticket['submit_email'] ?? '' ) ) ) ) : ''
        );
        $stash_message_id = (int) ( $stored['id'] ?? 0 );
        if ( $stash_message_id < 1 ) {
            throw new \RuntimeException( 'Grandy\'s Stash reply was classified but not stored in the ticket conversation.' );
        }

        $owner_email = \Metis\Modules\GrandyStash\GrandyStashRepository::ticketOwnerEmail( (int) $ticket['id'] );
        $sender_email = strtolower( trim( \metis_email_clean( (string) ( $message->all()['canonical_sender_email'] ?? '' ) ) ) );
        $submitter_email = strtolower( trim( \metis_email_clean( (string) ( $ticket['submit_email'] ?? '' ) ) ) );
        $mailbox_email = strtolower( trim( \metis_email_clean( (string) ( $message_row['provider_mailbox'] ?? $message->all()['provider_mailbox'] ?? '' ) ) ) );
        $body = trim( (string) $message->textBody() );
        if ( $body === '' ) {
            $body = trim( strip_tags( (string) $message->htmlBody() ) );
        }
        if ( class_exists( '\\Metis\\Modules\\GrandyStash\\ConversationSupport' ) ) {
            $body = \Metis\Modules\GrandyStash\ConversationSupport::extractLatestReplyText( $body );
        }
        $code = (string) ( $ticket['code'] ?? $ticket_code );
        $ticket_url = class_exists( '\\Metis\\Modules\\GrandyStash\\GrandyStashModule' )
            ? (string) \Metis\Modules\GrandyStash\GrandyStashModule::viewUrl( $code )
            : '';
        $submitter_url = '';
        if ( class_exists( '\\Metis\\Modules\\Donations\\RecurringDonationsService' )
            && \metis_email_is_valid( $submitter_email ) ) {
            $submitter_url = (string) \Metis\Modules\Donations\RecurringDonationsService::issuePortalAccessUrl(
                $submitter_email,
                'submissions',
                (int) ( $ticket['form_submission_id'] ?? 0 )
            );
        }
        $owner_notified = false;
        $submitter_notified = false;
        $submitter_thread = [ 'rfc_message_id' => '', 'references_header' => '', 'subject' => '' ];
        if ( class_exists( '\\Metis\\Modules\\GrandyStash\\GrandyStashRepository' ) ) {
            $submitter_thread = \Metis\Modules\GrandyStash\GrandyStashRepository::latestSubmitterThreadHeaders( (int) $ticket['id'], $submitter_email );
        }
        $submitter_references = class_exists( '\\Metis\\Modules\\GrandyStash\\ConversationSupport' )
            ? \Metis\Modules\GrandyStash\ConversationSupport::buildReferencesHeader(
                [ (string) ( $submitter_thread['references_header'] ?? '' ) ],
                (string) ( $submitter_thread['rfc_message_id'] ?? '' )
            )
            : (string) ( $submitter_thread['references_header'] ?? '' );

        // A reply from the assigned owner is a staff response to the submitter.
        // Forward it to the original submission address while keeping the Grandy's
        // mailbox as Reply-To so the next response is ingested into this ticket.
        if ( $owner_email !== '' && $sender_email === $owner_email
            && \metis_email_is_valid( $submitter_email ) && $submitter_email !== $owner_email
            && class_exists( '\\Metis\\Core\\Services\\EmailService' ) ) {
            $subject = (string) ( $submitter_thread['subject'] ?? '' );
            if ( $subject === '' ) {
                $subject = '[' . $code . '] Response from Grandy\'s Stash';
            } elseif ( ! preg_match( '/^re:\s*/i', $subject ) ) {
                $subject = 'Re: ' . $subject;
            }
            $html = self::notificationHtml( 'Grandy\'s Stash response', 'A response was added to your request.', $code, $body, $submitter_url, 'View your request' );
            $send = \Metis\Core\Services\EmailService::sendHtml( $submitter_email, $subject, $html, [
                'module'   => 'grandys_stash',
                'reply_to' => $mailbox_email,
                'internal_reference' => $code,
                'in_reply_to' => (string) ( $submitter_thread['rfc_message_id'] ?? '' ),
                'references' => $submitter_references,
            ] );
            $submitter_notified = ! empty( $send['ok'] );
            if ( $submitter_notified ) {
                \Metis\Modules\GrandyStash\GrandyStashRepository::recordOutboundNotificationMessage( (int) $ticket['id'], [
                    'mailbox_email'       => $mailbox_email,
                    'subject'             => $subject,
                    'sender_email'        => $mailbox_email,
                    'recipient_email'     => $submitter_email,
                    'recipients_json'     => [ $submitter_email ],
                    'text_body'           => $body,
                    'html_body'           => $html,
                    'delivery_status'     => 'sent',
                ] );
            }
        }

        if ( $owner_email !== '' && $owner_email !== $sender_email && class_exists( '\\Metis\\Core\\Services\\EmailService' ) ) {
            $subject = '[' . $code . '] New reply received';
            $html = self::notificationHtml( 'New Grandy\'s Stash reply', 'A new reply was received for a ticket assigned to you.', $code, $body, $ticket_url, 'View ticket' );
            $owner_notified = ! empty( \Metis\Core\Services\EmailService::sendHtml( $owner_email, $subject, $html, [
                'module' => 'grandys_stash',
                'reply_to' => $sender_email,
                'internal_reference' => $code,
                'in_reply_to' => (string) ( $message->all()['rfc_message_id'] ?? '' ),
                'references' => (string) ( $message->all()['references_header'] ?? '' ),
            ] )['ok'] );
        }

        return [
            'handled'  => true,
            'status'   => 'handled',
            'metadata' => [
                'ticket_id'    => (int) ( $ticket['id'] ?? 0 ),
                'ticket_code'  => $ticket_code,
                'stash_message_id' => $stash_message_id,
                'owner_notified' => $owner_notified,
                'submitter_notified' => $submitter_notified,
            ],
            'links' => [
                [
                    'module_slug' => 'grandys_stash',
                    'entity_type' => 'ticket',
                    'entity_id'   => (int) ( $ticket['id'] ?? 0 ),
                    'link_type'   => 'thread',
                    'metadata'    => [ 'ticket_code' => $ticket_code ],
                ],
            ],
        ];
    }

    private static function notificationHtml( string $heading, string $intro, string $ticket_code, string $body, string $url, string $label ): string {
        $logo = function_exists( 'metis_portal_logo_url' ) ? trim( (string) \metis_portal_logo_url() ) : '';
        $logo_html = $logo !== '' ? '<tr><td style="padding:26px 30px 0"><img src="' . \metis_escape_url( $logo ) . '" alt="' . \metis_escape_attr( function_exists( 'metis_organization_name' ) ? \metis_organization_name() : 'Organization' ) . '" style="display:block;max-width:210px;max-height:82px;width:auto;height:auto;border:0"></td></tr>' : '';
        $safe_body = nl2br( \metis_escape_html( \metis_text_clean( $body ) ) );
        $ticket = \metis_escape_html( $ticket_code );
        $html = '<div style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,sans-serif;color:#172033">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f5f7fb"><tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;border-collapse:collapse;background:#ffffff;border:1px solid #dfe5f1;border-radius:10px;overflow:hidden">'
            . $logo_html
            . '<tr><td style="padding:28px 30px 10px"><h1 style="margin:0;color:#172033;font-size:24px;line-height:1.2">' . \metis_escape_html( $heading ) . '</h1></td></tr>'
            . '<tr><td style="padding:0 30px 18px;color:#596579;font-size:15px;line-height:1.6">' . \metis_escape_html( $intro ) . '</td></tr>'
            . '<tr><td style="padding:0 30px 10px;font-size:15px"><strong>Ticket:</strong> ' . $ticket . '</td></tr>'
            . '<tr><td style="padding:0 30px 24px"><div style="padding:16px;border:1px solid #dfe5f1;border-radius:8px;background:#f8fafc;font-size:15px;line-height:1.6">' . $safe_body . '</div></td></tr>';
        if ( $url !== '' ) {
            $safe_url = \metis_escape_url( $url );
            $html .= '<tr><td style="padding:0 30px 28px"><a href="' . $safe_url . '" style="display:inline-block;background:#2754d8;color:#ffffff;text-decoration:none;font-weight:700;border-radius:6px;padding:12px 18px">' . \metis_escape_html( $label ) . '</a><p style="margin:14px 0 0;color:#596579;font-size:13px;line-height:1.5">If the button does not work, copy and paste this link into your browser:<br><a href="' . $safe_url . '" style="color:#2754d8;word-break:break-all">' . \metis_escape_html( $url ) . '</a></p></td></tr>';
        }
        return $html . '</table></td></tr></table></div>';
    }
}
