<?php

namespace App\Enums;

/**
 * The node vocabulary of a workflow definition.
 *
 * Deliberately BPMN-shaped without being BPMN: the same twelve concepts, named
 * so a risk manager can read the JSON, and mappable onto bpmn_xml later for
 * import/export without another schema change.
 */
enum WorkflowNodeType: string
{
    case Start = 'start';
    case Task = 'task';
    case Approval = 'approval';
    case ExclusiveGateway = 'exclusive_gateway';
    case ParallelGateway = 'parallel_gateway';
    case Join = 'join';
    case Timer = 'timer';
    case ServiceTask = 'service_task';
    case SubProcess = 'sub_process';
    case Escalation = 'escalation';
    case Notification = 'notification';
    case End = 'end';

    /**
     * Nodes that create a workflow_task and wait for a human.
     *
     * Everything else is executed by the engine the moment control reaches it,
     * which is why advance() loops rather than stepping once.
     */
    public function waitsForHuman(): bool
    {
        return in_array($this, [self::Task, self::Approval], true);
    }

    public function isGateway(): bool
    {
        return in_array($this, [self::ExclusiveGateway, self::ParallelGateway, self::Join], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Start => 'Start',
            self::Task => 'Task',
            self::Approval => 'Approval',
            self::ExclusiveGateway => 'Decision (exclusive)',
            self::ParallelGateway => 'Fork (parallel)',
            self::Join => 'Join',
            self::Timer => 'Timer',
            self::ServiceTask => 'Service task',
            self::SubProcess => 'Sub-process',
            self::Escalation => 'Escalation',
            self::Notification => 'Notification',
            self::End => 'End',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
