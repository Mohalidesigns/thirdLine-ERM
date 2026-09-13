<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Full-organization roles
    |--------------------------------------------------------------------------
    |
    | Roles that see every node in the organizational graph regardless of the
    | subtree their user record is pinned to. Everyone else is confined to the
    | subtree rooted at users.scope_entity_id (NULL still means org-wide, so
    | node scoping is opt-in per user).
    |
    */

    'full_org_roles' => [
        'super-admin',
        'chief-risk-officer',
        'risk-manager',
        'compliance-officer',
        'board-member',
    ],

    /*
    |--------------------------------------------------------------------------
    | Unassigned records
    |--------------------------------------------------------------------------
    |
    | entity_id is nullable across risks, controls, issues, loss events and
    | KRIs, and much existing data predates the graph. When true, a
    | subtree-limited user can still see records that sit on no node at all —
    | otherwise a migration that has not yet assigned entities would blank
    | their screens entirely.
    |
    */

    'subtree_users_see_unassigned' => true,

];
