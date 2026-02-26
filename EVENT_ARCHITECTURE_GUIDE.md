# Event-Driven Architecture Implementation Guide

## Overview

This document describes the complete event-driven architecture implemented for the GRC Platform. The system uses Laravel's event system to decouple business logic and enable automatic workflows when key GRC operations occur.

## Architecture Components

### 1. Event Classes (9 Total)

Located in `/app/Events/`, these classes represent significant GRC events:

#### ControlUpdated
- **Properties**: Control $control, array $changedFields
- **Purpose**: Fired when a control is updated
- **Listeners**: RecalculateResidualRisk, SendNotification

#### KriBreachDetected
- **Properties**: KeyRiskIndicator $kri, KriMeasurement $measurement, string $breachLevel
- **Purpose**: Fired when KRI measurements breach thresholds (red/amber)
- **Listeners**: EscalateRiskOnKriBreach, SendNotification

#### LossEventCreated
- **Properties**: LossEvent $lossEvent
- **Purpose**: Fired when a new loss event is recorded
- **Listeners**: EvaluateRegulatoryThresholds, FlagRiskForReview, SendNotification

#### LossEventAmountChanged
- **Properties**: LossEvent $lossEvent, int $oldAmountKobo, int $newAmountKobo
- **Purpose**: Fired when loss amount is modified
- **Listeners**: EvaluateRegulatoryThresholds

#### TreatmentCompleted
- **Properties**: TreatmentPlan $treatment, Risk $risk
- **Purpose**: Fired when a treatment plan is marked complete
- **Listeners**: TriggerRiskReassessment, SendNotification

#### IssueOverdue
- **Properties**: Issue $issue, int $daysOverdue
- **Purpose**: Fired when issues pass their due date
- **Listeners**: SendNotification

#### RiskAppetiteBreached
- **Properties**: RiskCategory $category, RiskAppetite $appetite, float $currentPosition, float $threshold
- **Purpose**: Fired when risk appetite limits are exceeded
- **Listeners**: SendNotification

#### AssessmentApproved
- **Properties**: RiskAssessment $assessment, Risk $risk
- **Purpose**: Fired when a risk assessment is approved
- **Listeners**: UpdateRiskFromAssessment, SendNotification

#### NearMissConverted
- **Properties**: NearMiss $nearMiss, LossEvent $lossEvent
- **Purpose**: Fired when a near miss is converted to a loss event
- **Listeners**: SendNotification

### 2. Listener Classes (7 Total)

Located in `/app/Listeners/`, these classes handle event logic:

#### RecalculateResidualRisk
Recalculates residual risk scores for all risks linked to an updated control.

```php
// Usage pattern:
foreach ($event->control->risks as $risk) {
    $service->recalculateForRisk($risk);
}
```

#### EscalateRiskOnKriBreach
Flags linked risks for review, updates risk velocity to 'increasing', and creates audit trail entries.

```php
// Actions:
- Updates risk_velocity to 'increasing'
- Sets review_required = true
- Sets next_review_date to +7 days
- Records audit trail entry
- Creates domain event for notifications
```

#### EvaluateRegulatoryThresholds
Checks loss amounts against regulatory thresholds (CBN, NFIU) and creates domain events for violations.

#### SendNotification
Universal listener that creates notification log entries for all events. Maps event types to notification templates with appropriate priority levels.

```php
// Priority mapping:
- KriBreachDetected (red) → critical
- IssueOverdue (>30 days) → critical
- IssueOverdue (≤30 days) → high
- LossEventCreated → high
- Others → medium
```

#### UpdateRiskFromAssessment
Updates risk scores from approved assessments using the RiskScoringService.

#### TriggerRiskReassessment
Applies treatment's expected risk reduction percentage to residual score:

```php
residualScore = risk.residual_score * (1 - (expected_reduction / 100))
```

#### FlagRiskForReview
Flags risk for mandatory review if it has 3+ loss events in 12 months.

### 3. Event Service Provider

**File**: `/app/Providers/EventServiceProvider.php`

Centralized configuration of all event-listener mappings:

```php
protected $listen = [
    ControlUpdated::class => [
        RecalculateResidualRisk::class,
        SendNotification::class,
    ],
    // ... 8 more event mappings
];
```

**Registration**: Added to `/bootstrap/providers.php`

### 4. Notification System

#### NotificationService
Static helper class for notification operations:

```php
// Send notification
NotificationService::send($orgId, $userId, $type, $subject, $body, $metadata);

// Retrieve notifications
NotificationService::getUnread($userId, $limit);
NotificationService::getUnreadCount($userId);

// Mark as read
NotificationService::markAsRead($notificationId);
NotificationService::markAllAsRead($userId);
```

#### Database Schema
The `notifications_log` table includes:
- `id`, `organization_id`, `user_id`
- `channel`, `type`, `subject`, `body`
- `status`, `read_at`
- `notification_category`, `action_url`, `priority`
- `metadata` (JSON)

#### NotificationController
RESTful endpoint for managing notifications:

```
GET  /notifications → index()
POST /notifications/{id}/mark-read → markAsRead()
POST /notifications/mark-all-read → markAllAsRead()
GET  /notifications/unread-count → getUnreadCount()
GET  /notifications/filter → filter()
```

#### Notification View
Interactive Blade template with:
- Notification list with priority badges
- Filter by type and priority
- Mark as read buttons
- Direct action links
- AJAX unread count updates

### 5. Scheduled Commands

Located in `/app/Console/Commands/`:

#### CheckOverdueIssues
- **Schedule**: Daily at 08:00
- **Action**: Finds open issues past due date, dispatches IssueOverdue events
- **Command**: `php artisan issues:check-overdue`

#### CheckKriBreaches
- **Schedule**: Daily at 07:00
- **Action**: Compares latest KRI measurements against thresholds, dispatches KriBreachDetected events
- **Command**: `php artisan kri:check-breaches`
- **Logic**: Checks both red and amber thresholds with direction-aware comparison

#### CheckOverdueTreatments
- **Schedule**: Daily at 08:30
- **Action**: Updates overdue treatments to 'overdue' status, creates notifications
- **Command**: `php artisan treatments:check-overdue`

#### CheckRegulatoryDeadlines
- **Schedule**: Twice daily (08:00 and 16:00)
- **Action**: Checks for approaching CBN/NFIU reporting deadlines within 48 hours
- **Command**: `php artisan regulatory:check-deadlines`

### 6. How to Use

#### Dispatching Events

In your controllers or services:

```php
// When a control is updated
ControlUpdated::dispatch($control, $changedFields);

// When KRI measurement breaches threshold
KriBreachDetected::dispatch($kri, $measurement, 'red');

// When loss event is created
LossEventCreated::dispatch($lossEvent);

// When treatment is completed
TreatmentCompleted::dispatch($treatment, $risk);

// When assessment is approved
AssessmentApproved::dispatch($assessment, $risk);
```

#### Adding New Events

1. Create event class in `/app/Events/EventName.php`
2. Create listener classes in `/app/Listeners/ListenerName.php`
3. Register in `EventServiceProvider::$listen`
4. Listeners execute automatically when event is dispatched

#### Custom Notifications

The SendNotification listener can be extended to handle custom event types:

```php
// In SendNotification::handle()
'YourEventName' => [
    'type' => 'custom_notification_type',
    'subject' => "Your Subject",
    'body' => "Your body",
    'category' => 'custom_category',
    'priority' => 'medium', // critical, high, medium, low
    'action_url' => "/path/to/resource",
],
```

## Data Flow Examples

### Example 1: Control Update Flow

```
User updates Control effectiveness
    ↓
ControlUpdated event dispatched
    ↓
├── RecalculateResidualRisk listener
│   └── Recalculates scores for linked risks
│
└── SendNotification listener
    └── Creates notification in notifications_log
```

### Example 2: KRI Breach Flow

```
CheckKriBreaches command runs (daily 07:00)
    ↓
Compares latest measurements against thresholds
    ↓
KriBreachDetected event dispatched (if breached)
    ↓
├── EscalateRiskOnKriBreach listener
│   ├── Flags linked risks for review
│   ├── Updates risk_velocity to 'increasing'
│   ├── Records audit trail entries
│   └── Creates domain_events
│
└── SendNotification listener
    └── Creates critical/high priority notification
```

### Example 3: Loss Event Flow

```
User creates Loss Event
    ↓
LossEventCreated event dispatched
    ↓
├── EvaluateRegulatoryThresholds listener
│   └── Checks against CBN/NFIU thresholds
│
├── FlagRiskForReview listener
│   └── Flags risk if 3+ events in 12 months
│
└── SendNotification listener
    └── Creates high priority notification
```

## Configuration

### Enable/Disable Events

Events are automatically dispatched when `Event::dispatch()` is called. To prevent dispatch in testing:

```php
// In tests
Event::fake();

// Or for specific events
Event::fake([ControlUpdated::class]);
```

### Queue Listeners

The `SendNotification` listener implements `ShouldQueue` interface, meaning it will queue if a queue driver is configured:

```php
// .env
QUEUE_CONNECTION=database
```

### Adjust Schedule Times

Edit `/routes/console.php`:

```php
Schedule::command('issues:check-overdue')->dailyAt('09:00'); // Changed from 08:00
```

## Monitoring and Debugging

### Check Scheduled Tasks
```bash
php artisan schedule:list
```

### Run Commands Manually
```bash
php artisan issues:check-overdue
php artisan kri:check-breaches
php artisan treatments:check-overdue
php artisan regulatory:check-deadlines
```

### View Event Logs
Query the `notifications_log` table:

```sql
SELECT * FROM notifications_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY created_at DESC;
```

### Debug Event Dispatch
Enable event debug mode in a service:

```php
// Listen to all events for debugging
Event::listen(function ($event) {
    \Log::debug('Event dispatched', [
        'event' => get_class($event),
        'data' => method_exists($event, 'toArray') ? $event->toArray() : (array)$event
    ]);
});
```

## Performance Considerations

1. **Listeners are synchronous by default** - Use `ShouldQueue` for long operations
2. **Database operations** - EscalateRiskOnKriBreach updates multiple risks; consider batch operations
3. **Daily commands** - Schedule at off-peak times (early morning recommended)
4. **Notification volume** - Clean old notifications periodically:

```sql
DELETE FROM notifications_log
WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

## Future Enhancements

1. Add webhook listeners for external system integration
2. Implement event versioning for backward compatibility
3. Add event replay functionality for audit purposes
4. Create event analytics dashboard
5. Add Slack/Email channel support to SendNotification
6. Implement event priority queue for critical events
7. Add event filtering and conditional listeners
