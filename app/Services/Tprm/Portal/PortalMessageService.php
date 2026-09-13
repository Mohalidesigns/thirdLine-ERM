<?php

namespace App\Services\Tprm\Portal;

use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentMessage;
use App\Models\Tprm\Finding;
use App\Models\Tprm\PortalUser;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Threaded messaging on an assessment or a finding — FR-PRT-09, "the single
 * most-cited missing feature in competitor reviews".
 *
 * WHERE THIS DOES NOT EXIST, THE CONVERSATION HAPPENS IN EMAIL, and that is
 * the whole argument for it. The reviewer's question and the vendor's answer
 * end up in two inboxes, neither is on the record, and the next reviewer a
 * year later inherits the answer without the question — or, more often,
 * inherits neither and asks again.
 *
 * BOTH SIDES ARE NOTIFIED AND THE PATHS ARE NOT SYMMETRICAL, because the two
 * populations are not. Our staff have `notifications_log` and a bell; the
 * vendor has an email address and nothing else. A single "notify" that assumed
 * one mechanism would silently drop half the conversation.
 */
class PortalMessageService
{
    /**
     * Post as the vendor.
     *
     * @throws InvalidArgumentException
     */
    public function postFromVendor(
        PortalUser $user,
        string $body,
        ?Assessment $assessment = null,
        ?Finding $finding = null,
        ?int $responseId = null,
    ): AssessmentMessage {
        $this->assertOneSubject($assessment, $finding);
        $this->assertVendorOwns($user, $assessment, $finding);

        $message = AssessmentMessage::create([
            'organization_id' => $user->organization_id,
            'assessment_id' => $assessment?->getKey(),
            'finding_id' => $finding?->getKey(),
            'response_id' => $responseId,
            'author_type' => AssessmentMessage::AUTHOR_VENDOR,
            'author_id' => $user->getKey(),
            'body' => $body,
        ]);

        $this->notifyInternal($message, $assessment, $finding);

        return $message;
    }

    /**
     * Post as one of our people.
     *
     * @throws InvalidArgumentException
     */
    public function postFromInternal(
        User $user,
        string $body,
        ?Assessment $assessment = null,
        ?Finding $finding = null,
        ?int $responseId = null,
    ): AssessmentMessage {
        $this->assertOneSubject($assessment, $finding);

        $organizationId = (int) ($assessment->organization_id ?? $finding?->organization_id);

        return AssessmentMessage::create([
            'organization_id' => $organizationId,
            'assessment_id' => $assessment?->getKey(),
            'finding_id' => $finding?->getKey(),
            'response_id' => $responseId,
            'author_type' => AssessmentMessage::AUTHOR_INTERNAL,
            'author_id' => $user->id,
            'body' => $body,
        ]);
    }

    /**
     * One thread, oldest first.
     *
     * ORDERED BY ID, NOT BY `created_at`. Two messages posted in the same
     * second — a reviewer answering immediately, or a seeded fixture — sort
     * arbitrarily by timestamp, and a conversation whose order changes between
     * page loads reads as a different conversation.
     *
     * @return Collection<int, AssessmentMessage>
     */
    public function thread(?Assessment $assessment = null, ?Finding $finding = null): Collection
    {
        $this->assertOneSubject($assessment, $finding);

        return AssessmentMessage::query()
            ->when($assessment !== null, fn ($q) => $q->where('assessment_id', $assessment->getKey()))
            ->when($finding !== null, fn ($q) => $q->where('finding_id', $finding->getKey()))
            ->orderBy('id')
            ->get();
    }

    /**
     * Mark everything the other side wrote as read.
     *
     * TAKES THE READER'S SIDE rather than a message list: "mark as read" means
     * "I have seen what THEY said", and a caller passing ids is a caller that
     * can mark its own messages read and zero its own unread count.
     */
    public function markRead(string $readerType, ?Assessment $assessment = null, ?Finding $finding = null): int
    {
        $this->assertOneSubject($assessment, $finding);

        $authorToMark = $readerType === AssessmentMessage::AUTHOR_VENDOR
            ? AssessmentMessage::AUTHOR_INTERNAL
            : AssessmentMessage::AUTHOR_VENDOR;

        return AssessmentMessage::query()
            ->when($assessment !== null, fn ($q) => $q->where('assessment_id', $assessment->getKey()))
            ->when($finding !== null, fn ($q) => $q->where('finding_id', $finding->getKey()))
            ->where('author_type', $authorToMark)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function unreadForVendor(PortalUser $user): int
    {
        return AssessmentMessage::query()
            ->where('author_type', AssessmentMessage::AUTHOR_INTERNAL)
            ->whereNull('read_at')
            ->where(function ($query) use ($user): void {
                $query
                    ->whereHas('assessment.engagement', fn ($q) => $q->where('third_party_id', $user->third_party_id))
                    ->orWhereHas('finding', fn ($q) => $q->where('third_party_id', $user->third_party_id));
            })
            ->count();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertOneSubject(?Assessment $assessment, ?Finding $finding): void
    {
        if (($assessment === null) === ($finding === null)) {
            throw new InvalidArgumentException(
                'A message thread hangs from exactly one of an assessment or a finding.'
            );
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertVendorOwns(PortalUser $user, ?Assessment $assessment, ?Finding $finding): void
    {
        $thirdPartyId = $assessment !== null
            ? (int) $assessment->loadMissing('engagement')->engagement?->third_party_id
            : (int) $finding?->third_party_id;

        if (! $user->actsFor($thirdPartyId)) {
            throw new InvalidArgumentException('That thread does not belong to your organisation.');
        }
    }

    private function notifyInternal(AssessmentMessage $message, ?Assessment $assessment, ?Finding $finding): void
    {
        $ownerId = $assessment !== null
            ? $assessment->loadMissing('engagement')->engagement?->relationship_owner_id
            : $finding?->owner_id;

        if ($ownerId === null) {
            return;
        }

        NotificationService::send(
            organizationId: (int) $message->organization_id,
            userId: (int) $ownerId,
            type: 'tprm.portal.message',
            subject: $finding !== null
                ? 'Vendor replied on '.$finding->reference
                : 'Vendor message on an assessment',
            body: mb_strimwidth($message->body, 0, 200, '…'),
            metadata: [
                'message_id' => $message->getKey(),
                'assessment_id' => $message->assessment_id,
                'finding_id' => $message->finding_id,
            ],
        );
    }
}
