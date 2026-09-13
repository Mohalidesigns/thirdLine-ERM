<?php

namespace Database\Seeders\Bcms\Reference;

use App\Enums\Bcms\AlertSeverity;

/**
 * The shipped EMNS message templates.
 *
 * ONE ROW PER LOCALE, NOT A BLOB OF LOCALES. A WhatsApp Business template is
 * approved per locale by Meta and the approval reference belongs on the row it
 * approves. English plus Hausa, Yoruba, Igbo and Nigerian Pidgin is the set
 * Blueprint §7.2 makes a differentiator; Phase 0 ships English and Nigerian
 * Pidgin for the life-safety templates, because those are the two that must
 * work on day one and translation of the rest is a content deliverable with a
 * named reviewer rather than something to machine-generate into a life-safety
 * path.
 *
 * PLAIN LANGUAGE, SHORT SENTENCES, ONE INSTRUCTION FIRST. Blueprint §14 asks
 * for WCAG 2.1 AA and plain language; an evacuation message whose first
 * sentence is a preamble is a message read too late. The SMS renderings fit
 * inside 160 characters after the "THIS IS AN EXERCISE. " prefix, because a
 * concatenated SMS can arrive out of order on a congested Nigerian network and
 * the second part is the one that carries the instruction.
 *
 * `is_life_safety` PUTS TRAFFIC ON A DIFFERENT QUEUE. It is not a severity
 * level (ADR 0005, standing rule 6).
 */
class AlertTemplates
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'EVACUATE', 'locale' => 'en',
                'name' => 'Evacuate the building',
                'category' => 'evacuation',
                'severity' => AlertSeverity::LifeSafety->value,
                'life_safety' => true,
                'dual_approval' => true,
                'subject' => 'Evacuate now — {{site_name}}',
                'body' => 'Evacuate {{site_name}} now. Use the nearest safe exit. Do not use the lifts. Go to the assembly point at {{assembly_point}} and wait for the roll-call.',
                'sms' => 'EVACUATE {{site_name}} NOW. Nearest safe exit. No lifts. Assemble at {{assembly_point}}.',
                'voice' => 'This is an emergency notification. Evacuate {{site_name}} immediately. Use the nearest safe exit. Do not use the lifts. Go to the assembly point at {{assembly_point}}.',
                'variables' => ['site_name', 'assembly_point'],
            ],
            [
                'code' => 'EVACUATE', 'locale' => 'pcm',
                'name' => 'Comot for the building',
                'category' => 'evacuation',
                'severity' => AlertSeverity::LifeSafety->value,
                'life_safety' => true,
                'dual_approval' => true,
                'subject' => 'Comot now — {{site_name}}',
                'body' => 'Comot from {{site_name}} now now. Use the closest safe door. No enter lift. Go meet for {{assembly_point}} make dem count you.',
                'sms' => 'COMOT {{site_name}} NOW. Use closest safe door. No lift. Go {{assembly_point}}.',
                'voice' => 'Dis na emergency. Comot from {{site_name}} now now. Use di closest safe door. No enter lift. Go {{assembly_point}}.',
                'variables' => ['site_name', 'assembly_point'],
            ],
            [
                'code' => 'ROLLCALL', 'locale' => 'en',
                'name' => 'Are you safe? — roll-call',
                'category' => 'roll_call',
                'severity' => AlertSeverity::LifeSafety->value,
                'life_safety' => true,
                'dual_approval' => false,
                'subject' => 'Are you safe?',
                'body' => 'Are you safe? Reply 1 if you are safe. Reply 2 if you need help. Reply 3 if you are safe but cannot get to work.',
                'sms' => 'Are you safe? Reply 1 safe, 2 need help, 3 safe but cannot reach work.',
                'voice' => 'Are you safe? Press 1 if you are safe. Press 2 if you need help. Press 3 if you are safe but cannot get to work.',
                'variables' => [],
                'response_options' => [
                    ['value' => '1', 'label' => 'I am safe'],
                    ['value' => '2', 'label' => 'I need help'],
                    ['value' => '3', 'label' => 'Safe, cannot reach work'],
                ],
            ],
            [
                'code' => 'ROLLCALL', 'locale' => 'pcm',
                'name' => 'You dey safe? — roll-call',
                'category' => 'roll_call',
                'severity' => AlertSeverity::LifeSafety->value,
                'life_safety' => true,
                'dual_approval' => false,
                'subject' => 'You dey safe?',
                'body' => 'You dey safe? Reply 1 if you dey safe. Reply 2 if you need help. Reply 3 if you dey safe but you no fit reach work.',
                'sms' => 'You dey safe? Reply 1 safe, 2 need help, 3 safe but no fit reach work.',
                'voice' => 'You dey safe? Press 1 if you dey safe. Press 2 if you need help. Press 3 if you dey safe but you no fit reach work.',
                'variables' => [],
                'response_options' => [
                    ['value' => '1', 'label' => 'I dey safe'],
                    ['value' => '2', 'label' => 'I need help'],
                    ['value' => '3', 'label' => 'Safe, no fit reach work'],
                ],
            ],
            [
                'code' => 'ALLCLEAR', 'locale' => 'en',
                'name' => 'All clear',
                'category' => 'evacuation',
                'severity' => AlertSeverity::Urgent->value,
                'life_safety' => true,
                'dual_approval' => false,
                'subject' => 'All clear — {{site_name}}',
                'body' => 'All clear at {{site_name}}. It is safe to return. {{additional_instructions}}',
                'sms' => 'ALL CLEAR {{site_name}}. Safe to return.',
                'voice' => 'All clear at {{site_name}}. It is safe to return to the building.',
                'variables' => ['site_name', 'additional_instructions'],
            ],
            [
                'code' => 'CRISISCONVENE', 'locale' => 'en',
                'name' => 'Crisis team — convene',
                'category' => 'activation',
                'severity' => AlertSeverity::Critical->value,
                'life_safety' => false,
                'dual_approval' => false,
                'subject' => 'Crisis team convene — {{incident_reference}}',
                'body' => 'The crisis management team is convening for {{incident_reference}}: {{incident_title}}. Join at {{bridge}} by {{convene_by}}. Acknowledge this message.',
                'sms' => 'CRISIS TEAM CONVENE {{incident_reference}}. Join {{bridge}} by {{convene_by}}. Reply Y to acknowledge.',
                'voice' => 'The crisis management team is convening for incident {{incident_reference}}. Please join the bridge as soon as possible.',
                'variables' => ['incident_reference', 'incident_title', 'bridge', 'convene_by'],
            ],
            [
                'code' => 'CALLTREEACT', 'locale' => 'en',
                'name' => 'Call tree activation',
                'category' => 'activation',
                'severity' => AlertSeverity::Urgent->value,
                'life_safety' => false,
                'dual_approval' => false,
                'subject' => 'Call tree activated — {{tree_name}}',
                'body' => 'The {{tree_name}} call tree has been activated. Acknowledge this message, then contact everyone below you in the tree. If you cannot reach someone, contact their deputy and record it.',
                'sms' => '{{tree_name}} CALL TREE ACTIVATED. Acknowledge, then call everyone below you. Deputy if no answer.',
                'voice' => 'The {{tree_name}} call tree has been activated. Please acknowledge and contact everyone below you in the tree.',
                'variables' => ['tree_name'],
            ],
            [
                'code' => 'ITOUTAGE', 'locale' => 'en',
                'name' => 'IT service outage',
                'category' => 'it_outage',
                'severity' => AlertSeverity::Urgent->value,
                'life_safety' => false,
                'dual_approval' => false,
                'subject' => '{{service_name}} unavailable',
                'body' => '{{service_name}} is unavailable. {{workaround}} Next update at {{next_update}}.',
                'sms' => '{{service_name}} DOWN. {{workaround}} Update {{next_update}}.',
                'voice' => null,
                'variables' => ['service_name', 'workaround', 'next_update'],
            ],
            [
                'code' => 'EXREMINDER', 'locale' => 'en',
                'name' => 'Exercise countdown reminder',
                'category' => 'exercise',
                'severity' => AlertSeverity::Informational->value,
                'life_safety' => false,
                'dual_approval' => false,
                'subject' => '{{days_remaining}} days to {{exercise_name}}',
                'body' => '{{exercise_name}} is on {{scheduled_date}} at {{location}} — {{days_remaining}} days away. You have {{open_task_count}} readiness task(s) still open. {{blocking_note}}',
                'sms' => '{{exercise_name}} in {{days_remaining}} days ({{scheduled_date}}). {{open_task_count}} readiness task(s) open.',
                'voice' => null,
                'variables' => ['exercise_name', 'scheduled_date', 'location', 'days_remaining', 'open_task_count', 'blocking_note'],
            ],
            [
                'code' => 'EXBLOCKED', 'locale' => 'en',
                'name' => 'Exercise blocked by an open readiness task',
                'category' => 'exercise',
                'severity' => AlertSeverity::Advisory->value,
                'life_safety' => false,
                'dual_approval' => false,
                'subject' => 'Action needed — {{exercise_name}} is blocked',
                'body' => '{{exercise_name}} on {{scheduled_date}} cannot go ahead: {{blocking_task_count}} blocking readiness task(s) are still open and you own {{your_task_count}} of them. Close them or ask for an override.',
                'sms' => '{{exercise_name}} BLOCKED. {{your_task_count}} readiness task(s) you own are open. Close them before {{scheduled_date}}.',
                'voice' => null,
                'variables' => ['exercise_name', 'scheduled_date', 'blocking_task_count', 'your_task_count'],
            ],
            [
                'code' => 'CONTACTVERIFY', 'locale' => 'en',
                'name' => 'Verify your emergency contact details',
                'category' => 'hygiene',
                'severity' => AlertSeverity::Informational->value,
                'life_safety' => false,
                'dual_approval' => false,
                'subject' => 'Confirm how we reach you in an emergency',
                'body' => 'We last confirmed your emergency contact details on {{last_verified}}. Please check them at {{profile_link}}. These details are used only to reach you in an emergency.',
                'sms' => 'Please confirm your emergency contact details: {{profile_link}}. Used for emergencies only.',
                'voice' => null,
                'variables' => ['last_verified', 'profile_link'],
            ],
        ];
    }
}
