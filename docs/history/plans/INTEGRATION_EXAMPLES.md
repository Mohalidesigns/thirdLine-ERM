# Event-Driven Architecture Integration Examples

This document provides practical examples of how to integrate the event system into your GRC controllers and services.

## Controller Examples

### Control Controller - Dispatching ControlUpdated Event

```php
<?php

namespace App\Http\Controllers\Risk;

use App\Events\ControlUpdated;
use App\Models\Control;

class ControlController extends Controller
{
    public function update(Request $request, Control $control)
    {
        $original = $control->getAttributes();

        $control->update($request->validated());

        // Determine which fields changed
        $changedFields = [];
        foreach ($request->validated() as $field => $value) {
            if ($original[$field] ?? null !== $value) {
                $changedFields[$field] = [
                    'old' => $original[$field] ?? null,
                    'new' => $value,
                ];
            }
        }

        // Dispatch event with changed fields
        ControlUpdated::dispatch($control, $changedFields);

        return redirect()->route('controls.show', $control)
            ->with('success', 'Control updated successfully');
    }
}
```

### Loss Event Controller - Multiple Events

```php
<?php

namespace App\Http\Controllers\Risk;

use App\Events\LossEventCreated;
use App\Events\LossEventAmountChanged;
use App\Models\LossEvent;

class LossEventController extends Controller
{
    public function store(Request $request)
    {
        $lossEvent = LossEvent::create($request->validated());

        // Dispatch creation event
        LossEventCreated::dispatch($lossEvent);

        return redirect()->route('loss-events.show', $lossEvent)
            ->with('success', 'Loss event recorded');
    }

    public function updateAmount(Request $request, LossEvent $lossEvent)
    {
        $oldAmount = $lossEvent->gross_loss_amount_kobo;
        $newAmount = $request->input('amount_kobo');

        $lossEvent->update(['gross_loss_amount_kobo' => $newAmount]);

        // Dispatch amount change event
        LossEventAmountChanged::dispatch($lossEvent, $oldAmount, $newAmount);

        return redirect()->route('loss-events.show', $lossEvent)
            ->with('success', 'Amount updated');
    }
}
```

### Treatment Controller - Completion Event

```php
<?php

namespace App\Http\Controllers\Risk;

use App\Events\TreatmentCompleted;
use App\Models\TreatmentPlan;
use App\Models\Risk;

class TreatmentController extends Controller
{
    public function markComplete(TreatmentPlan $treatment)
    {
        $risk = $treatment->risk;

        $treatment->update([
            'status' => 'completed',
            'actual_completion_date' => now(),
        ]);

        // Dispatch completion event
        TreatmentCompleted::dispatch($treatment, $risk);

        return redirect()->route('treatments.show', $treatment)
            ->with('success', 'Treatment marked as completed');
    }
}
```

### Risk Assessment Controller - Approval Event

```php
<?php

namespace App\Http\Controllers\Risk;

use App\Events\AssessmentApproved;
use App\Models\RiskAssessment;
use App\Models\Risk;

class RiskAssessmentController extends Controller
{
    public function approve(RiskAssessment $assessment)
    {
        $risk = $assessment->risk;

        $assessment->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        // Dispatch approval event
        AssessmentApproved::dispatch($assessment, $risk);

        return redirect()->route('assessments.show', $assessment)
            ->with('success', 'Assessment approved');
    }
}
```

## Service Examples

### KRI Service - Dispatching Breach Events

```php
<?php

namespace App\Services;

use App\Events\KriBreachDetected;
use App\Models\KeyRiskIndicator;
use App\Models\KriMeasurement;

class KriMeasurementService
{
    public function recordMeasurement(
        KeyRiskIndicator $kri,
        float $value,
        string $comment = null
    ): KriMeasurement {
        $measurement = $kri->measurements()->create([
            'value' => $value,
            'measurement_date' => now(),
            'comment' => $comment,
            'recorded_by' => auth()->id(),
        ]);

        // Check for breach
        $breachLevel = $this->checkBreach($kri, $value);

        if ($breachLevel) {
            KriBreachDetected::dispatch($kri, $measurement, $breachLevel);
        }

        return $measurement;
    }

    private function checkBreach(KeyRiskIndicator $kri, float $value): ?string
    {
        if ($kri->red_threshold !== null) {
            $isBreached = $kri->threshold_comparison === 'greater_than'
                ? $value > $kri->red_threshold
                : $value < $kri->red_threshold;

            if ($isBreached) {
                return 'red';
            }
        }

        if ($kri->amber_threshold !== null) {
            $isBreached = $kri->threshold_comparison === 'greater_than'
                ? $value > $kri->amber_threshold
                : $value < $kri->amber_threshold;

            if ($isBreached) {
                return 'amber';
            }
        }

        return null;
    }
}
```

### Near Miss Service - Conversion Event

```php
<?php

namespace App\Services;

use App\Events\NearMissConverted;
use App\Models\NearMiss;
use App\Models\LossEvent;

class NearMissService
{
    public function convertToLossEvent(
        NearMiss $nearMiss,
        array $lossEventData
    ): LossEvent {
        // Create loss event from near miss
        $lossEvent = LossEvent::create([
            'organization_id' => $nearMiss->organization_id,
            'risk_id' => $nearMiss->risk_id,
            'title' => $nearMiss->title,
            'description' => $nearMiss->description,
            'gross_loss_amount_kobo' => $lossEventData['amount_kobo'] ?? 0,
            'event_reference' => LossEvent::generateReference(),
            'reported_by' => auth()->id(),
            'event_date' => now(),
            ...array_filter($lossEventData)
        ]);

        // Update near miss status
        $nearMiss->update([
            'status' => 'converted',
            'converted_to_loss_event_id' => $lossEvent->id,
            'converted_at' => now(),
        ]);

        // Dispatch conversion event
        NearMissConverted::dispatch($nearMiss, $lossEvent);

        return $lossEvent;
    }
}
```

## Model Hook Examples

### Using Model Events to Trigger Application Events

```php
<?php

namespace App\Models;

use App\Events\ControlUpdated;
use Illuminate\Database\Eloquent\Model;

class Control extends Model
{
    protected static function booted()
    {
        // Alternative: dispatch event from model observer
        static::updated(function ($control) {
            if ($control->isDirty(['effectiveness_score', 'status'])) {
                ControlUpdated::dispatch(
                    $control,
                    $control->getChanges()
                );
            }
        });
    }
}
```

Or use an Observer:

```php
<?php

namespace App\Observers;

use App\Events\ControlUpdated;
use App\Models\Control;

class ControlObserver
{
    public function updated(Control $control)
    {
        if ($control->isDirty(['effectiveness_score', 'status'])) {
            ControlUpdated::dispatch(
                $control,
                $control->getChanges()
            );
        }
    }
}
```

Register in `AppServiceProvider`:

```php
public function boot()
{
    Control::observe(ControlObserver::class);
}
```

## Testing Examples

### Testing Event Dispatch

```php
<?php

namespace Tests\Feature;

use App\Events\ControlUpdated;
use App\Models\Control;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ControlUpdateTest extends TestCase
{
    public function test_control_update_dispatches_event()
    {
        Event::fake();

        $control = Control::factory()->create();

        $this->patch(route('controls.update', $control), [
            'effectiveness_score' => 85,
        ]);

        Event::assertDispatched(ControlUpdated::class, function ($event) use ($control) {
            return $event->control->id === $control->id;
        });
    }
}
```

### Testing Event Listeners

```php
<?php

namespace Tests\Feature;

use App\Events\ControlUpdated;
use App\Listeners\RecalculateResidualRisk;
use App\Models\Control;
use App\Models\Risk;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RecalculateResidualRiskTest extends TestCase
{
    public function test_listener_recalculates_residual_risk()
    {
        $control = Control::factory()->create();
        $risk = Risk::factory()->create();
        $control->risks()->attach($risk);

        $oldScore = $risk->residual_score;

        $event = new ControlUpdated($control, ['effectiveness_score' => 85]);
        $listener = new RecalculateResidualRisk();

        $listener->handle($event);

        $risk->refresh();

        // Assert residual score was recalculated
        $this->assertNotEquals($oldScore, $risk->residual_score);
    }
}
```

### Testing Notifications

```php
<?php

namespace Tests\Feature;

use App\Events\LossEventCreated;
use App\Models\LossEvent;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LossEventNotificationTest extends TestCase
{
    public function test_loss_event_creates_notification()
    {
        $lossEvent = LossEvent::factory()->create();

        // Clear existing notifications
        DB::table('notifications_log')->delete();

        LossEventCreated::dispatch($lossEvent);

        // Assert notification was created
        $this->assertDatabaseHas('notifications_log', [
            'type' => 'loss_event_created',
            'organization_id' => $lossEvent->organization_id,
        ]);
    }
}
```

## API Examples

### Retrieving Notifications via API

```php
<?php

namespace App\Http\Controllers\Api;

use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationApiController extends Controller
{
    public function unread(): JsonResponse
    {
        $notifications = NotificationService::getUnread(auth()->id());

        return response()->json($notifications);
    }

    public function unreadCount(): JsonResponse
    {
        $count = NotificationService::getUnreadCount(auth()->id());

        return response()->json(['unread_count' => $count]);
    }
}
```

### Frontend JavaScript Integration

```javascript
// Fetch unread notification count every 30 seconds
setInterval(() => {
    fetch('/api/notifications/unread-count')
        .then(response => response.json())
        .then(data => {
            const badge = document.querySelector('[data-notification-badge]');
            if (badge) {
                badge.textContent = data.unread_count;
                badge.style.display = data.unread_count > 0 ? 'inline' : 'none';
            }
        });
}, 30000);

// Mark notification as read with AJAX
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('mark-read-btn')) {
        const notificationId = e.target.dataset.id;

        fetch(`/notifications/${notificationId}/mark-read`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        }).then(() => location.reload());
    }
});
```

## Scheduled Job Workflow Example

### Complete Daily Risk Review Workflow

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DailyRiskReview extends Command
{
    protected $signature = 'risk:daily-review';

    public function handle()
    {
        // 1. Check for KRI breaches (7:00 AM)
        $this->call('kri:check-breaches');

        // 2. Check for overdue issues (8:00 AM)
        $this->call('issues:check-overdue');

        // 3. Check for overdue treatments (8:30 AM)
        $this->call('treatments:check-overdue');

        // 4. Regulatory deadline checks (twice daily)
        $this->call('regulatory:check-deadlines');

        // 5. Generate executive summary
        $this->generateExecutiveSummary();
    }

    private function generateExecutiveSummary()
    {
        // Aggregate notifications from past 24 hours
        $summary = \DB::table('notifications_log')
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) as count')
            ->get();

        \Log::info('Daily Risk Review Summary:', $summary->toArray());
    }
}
```

Register in `routes/console.php`:

```php
Schedule::command('risk:daily-review')->dailyAt('07:00');
```

## Extending the System

### Adding a Custom Event

1. Create the event:
```php
// app/Events/RiskReviewScheduled.php
class RiskReviewScheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Risk $risk,
        public string $reason
    ) {}
}
```

2. Create listeners:
```php
// app/Listeners/NotifyRiskOwner.php
class NotifyRiskOwner
{
    public function handle(RiskReviewScheduled $event)
    {
        // Send notification to risk owner
    }
}
```

3. Register in EventServiceProvider:
```php
protected $listen = [
    // ... existing events
    \App\Events\RiskReviewScheduled::class => [
        \App\Listeners\NotifyRiskOwner::class,
    ],
];
```

4. Dispatch from your code:
```php
RiskReviewScheduled::dispatch($risk, 'KRI breach detected');
```
