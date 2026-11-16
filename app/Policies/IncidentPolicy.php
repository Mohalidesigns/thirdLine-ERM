<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('incident.view');
    }

    public function view(User $user, Incident $incident): bool
    {
        return $user->can('incident.view')
            || $incident->owner_id === $user->id
            || $incident->reported_by === $user->id
            || $incident->investigator_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('incident.create');
    }

    public function update(User $user, Incident $incident): bool
    {
        return $incident->status !== 'Closed'
            && ($user->can('incident.edit')
                || $incident->owner_id === $user->id
                || $incident->investigator_id === $user->id);
    }

    public function triage(User $user, Incident $incident): bool
    {
        return ($user->can('incident.triage') || $user->can('incident.edit'))
            && in_array($incident->status, ['Reported', 'Triaged'], true);
    }

    public function investigate(User $user, Incident $incident): bool
    {
        return $this->update($user, $incident) && ($user->can('incident.edit') || $user->can('incident.triage'));
    }

    public function close(User $user, Incident $incident): bool
    {
        return $user->can('incident.close')
            && in_array($incident->status, Incident::OPEN_STATUSES, true)
            && $incident->reported_by !== $user->id;
    }

    public function reopen(User $user, Incident $incident): bool
    {
        return $user->can('incident.close') && $incident->status === 'Closed';
    }

    public function notify(User $user, Incident $incident): bool
    {
        return $user->can('incident.notify') || $user->can('incident.edit');
    }

    public function delete(User $user, Incident $incident): bool
    {
        return $user->can('incident.delete');
    }
}
