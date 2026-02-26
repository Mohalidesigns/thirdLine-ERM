# Quick Reference - Event-Driven Architecture

## File Structure

```
/app/
├── Events/                          # 9 Event Classes
│   ├── AssessmentApproved.php
│   ├── ControlUpdated.php
│   ├── IssueOverdue.php
│   ├── KriBreachDetected.php
│   ├── LossEventAmountChanged.php
│   ├── LossEventCreated.php
│   ├── NearMissConverted.php
│   ├── RiskAppetiteBreached.php
│   └── TreatmentCompleted.php
│
├── Listeners/                       # 7 Event Listeners
│   ├── EscalateRiskOnKriBreach.php
│   ├── EvaluateRegulatoryThresholds.php
│   ├── FlagRiskForReview.php
│   ├── RecalculateResidualRisk.php
│   ├── SendNotification.php
│   ├── TriggerRiskReassessment.php
│   └── UpdateRiskFromAssessment.php
│
├── Providers/
│   └── EventServiceProvider.php     # Event-Listener Mapping
│
├── Services/
│   └── NotificationService.php      # Notification Helpers
│
├── Http/Controllers/Risk/
│   └── NotificationController.php   # Notification Routes
│
└── Console/Commands/                # 4 Scheduled Commands
    ├── CheckKriBreaches.php
    ├── CheckOverdueIssues.php
    ├── CheckOverdueTreatments.php
    └── CheckRegulatoryDeadlines.php

/database/migrations/
└── 2026_02_24_000001_add_notification_fields.php

/resources/views/risk/notifications/
└── index.blade.php

/bootstrap/
└── providers.php                    # Register EventServiceProvider

/routes/
└── console.php                      # Schedule Commands
```

## Event Dispatch Quick Start

### Dispatching Events

```php
// In controllers or services
use App\Events\ControlUpdated;
use App\Events\KriBreachDetected;
use App\Events\LossEventCreated;
use App\Events\TreatmentCompleted;
use App\Events\AssessmentApproved;

// Example: Control Updated
ControlUpdated::dispatch($control, ['effectiveness_score']);

// Example: KRI Breach
KriBreachDetected::dispatch($kri, $measurement, 'red');

// Example: Loss Event Created
LossEventCreated::dispatch($lossEvent);

// Example: Treatment Completed
TreatmentCompleted::dispatch($treatment, $risk);

// Example: Assessment Approved
AssessmentApproved::dispatch($assessment, $risk);
```

## Event-Listener Mapping

| Event | Listeners | Triggered When |
|-------|-----------|----------------|
| ControlUpdated | RecalculateResidualRisk, SendNotification | Control effectiveness changes |
| KriBreachDetected | EscalateRiskOnKriBreach, SendNotification | KRI threshold breached |
| LossEventCreated | EvaluateRegulatoryThresholds, FlagRiskForReview, SendNotification | New loss event recorded |
| LossEventAmountChanged | EvaluateRegulatoryThresholds | Loss amount modified |
| TreatmentCompleted | TriggerRiskReassessment, SendNotification | Treatment marked complete |
| IssueOverdue | SendNotification | Issue passes due date |
| RiskAppetiteBreached | (Ready for custom listeners) | Risk appetite exceeded |
| AssessmentApproved | UpdateRiskFromAssessment, SendNotification | Assessment approved |
| NearMissConverted | SendNotification | Near miss becomes loss event |

## Notification Management

### Send Notifications

```php
use App\Services\NotificationService;

NotificationService::send(
    $organizationId,
    $userId,
    'notification_type',
    'Subject Line',
    'Message body',
    ['metadata' => 'value']
);
```

### Retrieve Notifications

```php
// Get unread notifications
$notifications = NotificationService::getUnread($userId, 10);

// Get unread count
$count = NotificationService::getUnreadCount($userId);

// Mark as read
NotificationService::markAsRead($notificationId);
NotificationService::markAllAsRead($userId);
```

### API Endpoints

```
GET    /notifications                    # View all notifications
POST   /notifications/{id}/mark-read     # Mark single as read
POST   /notifications/mark-all-read      # Mark all as read
GET    /notifications/unread-count       # Get unread count (JSON)
GET    /notifications/filter?type=&priority= # Filter notifications
```

## Console Commands

### Manual Execution

```bash
# Check for overdue issues
php artisan issues:check-overdue

# Check for KRI breaches
php artisan kri:check-breaches

# Check for overdue treatments
php artisan treatments:check-overdue

# Check for regulatory deadlines
php artisan regulatory:check-deadlines
```

### Automatic Scheduling

Configured in `/routes/console.php`:

- `issues:check-overdue` → Daily 08:00
- `kri:check-breaches` → Daily 07:00
- `treatments:check-overdue` → Daily 08:30
- `regulatory:check-deadlines` → Twice daily (08:00, 16:00)

### Enable Scheduler

Add to crontab:
```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## Key Classes and Methods

### ControlUpdated Event
```php
class ControlUpdated {
    public Control $control;
    public array $changedFields;
}
```

### KriBreachDetected Event
```php
class KriBreachDetected {
    public KeyRiskIndicator $kri;
    public KriMeasurement $measurement;
    public string $breachLevel; // 'red' or 'amber'
}
```

### LossEventCreated Event
```php
class LossEventCreated {
    public LossEvent $lossEvent;
}
```

### TreatmentCompleted Event
```php
class TreatmentCompleted {
    public TreatmentPlan $treatment;
    public Risk $risk;
}
```

### AssessmentApproved Event
```php
class AssessmentApproved {
    public RiskAssessment $assessment;
    public Risk $risk;
}
```

## Testing Events

### Fake Events in Tests

```php
use Illuminate\Support\Facades\Event;

public function test_control_update_dispatches_event()
{
    Event::fake();

    // Your test code

    Event::assertDispatched(ControlUpdated::class);
}
```

### Test Listeners

```php
public function test_recalculate_listener()
{
    $control = Control::factory()->create();
    $risk = Risk::factory()->create();
    $control->risks()->attach($risk);

    $event = new ControlUpdated($control, ['effectiveness_score']);
    (new RecalculateResidualRisk())->handle($event);

    // Assert risk was recalculated
}
```

## Database Queries

### View Recent Notifications

```sql
SELECT * FROM notifications_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY created_at DESC;
```

### Count Notifications by Type

```sql
SELECT type, COUNT(*) as count
FROM notifications_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY type;
```

### Mark All Old Notifications as Read

```sql
UPDATE notifications_log
SET read_at = NOW()
WHERE read_at IS NULL
AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### Delete Old Notifications

```sql
DELETE FROM notifications_log
WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

## Environment Configuration

### Queue Driver (.env)

```
QUEUE_CONNECTION=database
```

### Enable Event Debugging

```php
// In AppServiceProvider or Event listener
Event::listen(function ($event) {
    Log::debug('Event dispatched: ' . class_basename($event));
});
```

## Common Issues & Solutions

### Issue: Events Not Firing
**Solution**: Ensure EventServiceProvider is registered in `bootstrap/providers.php`

### Issue: Scheduler Not Running
**Solution**: Add to crontab: `* * * * * cd /app && php artisan schedule:run`

### Issue: Notifications Not Creating
**Solution**: Check queue connection and ensure `notifications_log` table exists

### Issue: Commands Not Found
**Solution**: Run `php artisan list` to verify commands are registered

## Performance Tips

1. Use ShouldQueue on long-running listeners
2. Batch multiple database operations
3. Schedule checks during off-peak hours
4. Archive notifications older than 90 days
5. Monitor `notifications_log` table size
6. Use database indexing on frequently queried columns

## Extension Points

### Add New Event

1. Create `/app/Events/MyEvent.php`
2. Create `/app/Listeners/MyListener.php`
3. Register in `EventServiceProvider::$listen`
4. Dispatch with `MyEvent::dispatch(...)`

### Add Email Notifications

Extend `SendNotification` listener to send emails:

```php
Mail::to($user->email)->send(new NotificationMail($notification));
```

### Add Slack Integration

Create new listener `SendToSlack`:

```php
Slack::message('channel', 'Your message');
```

Register in `EventServiceProvider`

## Documentation Files

1. **EVENT_ARCHITECTURE_GUIDE.md** - Complete reference
2. **INTEGRATION_EXAMPLES.md** - Code examples and patterns
3. **IMPLEMENTATION_SUMMARY.txt** - Project overview
4. **QUICK_REFERENCE.md** - This file

## Support & Contact

For questions or issues:
- Review EVENT_ARCHITECTURE_GUIDE.md for complete documentation
- Check INTEGRATION_EXAMPLES.md for code samples
- Review database schema in migrations
- Test with Laravel Tinker: `php artisan tinker`
