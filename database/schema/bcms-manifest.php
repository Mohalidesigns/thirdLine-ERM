<?php

/*
 * THE FROZEN BCMS SCHEMA — Gate G0, acceptance criterion 2.
 *
 * Generated from a fresh `migrate` at the end of Phase 0 and compared against
 * the live database by `php artisan bcms:verify-schema`, which exits non-zero
 * on any difference in either direction.
 *
 * WHY A MANIFEST AND NOT JUST THE MIGRATIONS. Four parallel tracks build
 * against this schema for fourteen weeks and standing rule 2 forbids structural
 * migrations after Week 1. The migrations say what was intended; this says what
 * a fresh install actually has, and the two drift the first time somebody adds
 * a column in a hotfix branch and it merges cleanly. A track that needs a
 * column raises an ADR and regenerates this file in the same commit, which
 * makes the change visible in review rather than discoverable in production.
 *
 * TO REGENERATE after an approved ADR:
 *   php artisan bcms:verify-schema --write
 */

return [
    'bcms_aars' => [
        'ai_draft_generated_at', 'ai_generated', 'approved_at', 'approved_by', 'created_at',
        'created_by', 'deleted_at', 'distributed_at', 'id', 'iso_clause_ref', 'occurrence_id',
        'organization_id', 'participant_feedback', 'quantitative_results', 'status', 'summary',
        'updated_at', 'updated_by', 'uuid', 'what_failed', 'what_worked',
    ],
    'bcms_alert_recipients' => [
        'acknowledged_at', 'alert_id', 'contact_id', 'contact_name_snapshot', 'created_at',
        'escalated_at', 'escalated_to_contact_id', 'id', 'organization_id', 'resolved_channels',
        'response_text', 'response_value', 'status', 'updated_at',
    ],
    'bcms_alert_templates' => [
        'body', 'category', 'channel_renderings', 'code', 'created_at', 'created_by',
        'default_audience_rule', 'default_channel_set', 'deleted_at', 'id', 'is_active',
        'is_life_safety', 'is_system_default', 'iso_clause_ref', 'locale', 'name',
        'organization_id', 'requires_dual_approval', 'severity', 'subject', 'updated_at',
        'updated_by', 'variables', 'whatsapp_template_name',
    ],
    'bcms_alerts' => [
        'ack_window_minutes', 'actual_cost_minor', 'ai_generated', 'approved_at', 'approved_by',
        'audience_rule', 'channels', 'created_at', 'created_by', 'currency', 'deleted_at',
        'dispatched_at', 'escalation_enabled', 'estimated_cost_minor', 'id', 'incident_id',
        'initiated_by', 'is_simulation', 'iso_clause_ref', 'message', 'occurrence_id',
        'organization_id', 'recipient_count', 'response_options', 'response_required',
        'second_approved_at', 'second_approved_by', 'severity', 'status', 'template_id', 'title',
        'updated_at', 'updated_by', 'uuid',
    ],
    'bcms_applications' => [
        'code', 'created_at', 'created_by', 'deleted_at', 'description', 'external_ref',
        'hosting_location', 'hosting_model', 'id', 'is_active', 'name', 'organization_id',
        'owner_id', 'primary_site_id', 'tprm_engagement_id', 'updated_at', 'updated_by', 'uuid',
        'vendor_name',
    ],
    'bcms_audit_logs' => [
        'actor_id', 'actor_label', 'after', 'auditable_id', 'auditable_type', 'before',
        'created_at', 'event', 'id', 'ip_address', 'organization_id',
    ],
    'bcms_bia_assessments' => [
        'ai_drafted_at', 'ai_generated', 'ai_reasoning', 'approved_at', 'approved_by',
        'assessor_id', 'campaign_id', 'chase_count', 'chased_at', 'created_at', 'created_by',
        'deleted_at', 'derived_mtpd_hours', 'escalated_at', 'escalated_to_user_id', 'id',
        'iso_clause_ref', 'mbco_description', 'min_staff_required', 'mtpd_hours',
        'organization_id', 'peak_periods', 'process_id', 'rpo_minutes', 'rto_hours', 'status',
        'submitted_at', 'updated_at', 'updated_by', 'uuid', 'workaround_available',
        'workaround_max_duration_hours',
    ],
    'bcms_bia_campaigns' => [
        'closes_at', 'created_at', 'created_by', 'cycle', 'deleted_at', 'id', 'iso_clause_ref',
        'name', 'opens_at', 'organization_id', 'programme_id', 'response_rate', 'status',
        'updated_at', 'updated_by', 'uuid',
    ],
    'bcms_bia_impacts' => [
        'assessment_id', 'created_at', 'currency', 'financial_amount_minor', 'horizon', 'id',
        'impact_category', 'narrative', 'organization_id', 'severity_score', 'updated_at',
    ],
    'bcms_blackout_periods' => [
        'business_unit_id', 'category', 'created_at', 'created_by', 'deleted_at', 'ends_on', 'id',
        'is_active', 'is_hard_block', 'is_system_default', 'name', 'organization_id', 'recurrence',
        'starts_on', 'updated_at', 'updated_by',
    ],
    'bcms_call_tree_nodes' => [
        'call_tree_id', 'contact_id', 'created_at', 'deputy_contact_id', 'deputy_user_id',
        'expected_response_minutes', 'id', 'is_must_reach', 'notes', 'organization_id',
        'parent_node_id', 'primary_channel', 'role_label', 'secondary_channel', 'sequence', 'tier',
        'updated_at', 'user_id',
    ],
    'bcms_call_tree_test_nodes' => [
        'acknowledged_at', 'attempts', 'channel_used', 'contact_name_snapshot', 'contacted_at',
        'created_at', 'downstream_blocked_count', 'id', 'node_id', 'notes', 'organization_id',
        'outcome', 'response_minutes', 'role_label_snapshot', 'test_id', 'tier_snapshot',
        'updated_at',
    ],
    'bcms_call_tree_tests' => [
        'announced', 'call_tree_id', 'completed_at', 'completion_rate', 'created_at', 'created_by',
        'data_quality_failures', 'deputy_activation_rate', 'first_attempt_rate', 'id',
        'initiated_at', 'initiated_by', 'iso_clause_ref', 'mode', 'nodes_reached', 'nodes_total',
        'occurrence_id', 'organization_id', 'scorecard', 'total_cascade_minutes', 'updated_at',
        'updated_by', 'uuid',
    ],
    'bcms_call_trees' => [
        'activation_authority_user_id', 'approved_at', 'approved_by', 'business_unit_id',
        'created_at', 'created_by', 'deleted_at', 'id', 'iso_clause_ref', 'last_reviewed_at',
        'name', 'organization_id', 'review_frequency_days', 'site_id', 'source', 'status',
        'tree_type', 'updated_at', 'updated_by', 'uuid', 'version',
    ],
    'bcms_clause_refs' => [
        'citation', 'clause', 'code', 'created_at', 'export_packs', 'id', 'is_mandatory_record',
        'requirement', 'sort_order', 'standard', 'title', 'updated_at',
    ],
    'bcms_contacts' => [
        'ad_object_guid', 'ad_synced_at', 'business_unit_id', 'channel_preferences',
        'consecutive_failures', 'consent_captured_at', 'consent_status', 'consent_withdrawn_at',
        'created_at', 'created_by', 'deleted_at', 'email', 'employee_id', 'full_name',
        'geo_last_known', 'id', 'is_active', 'last_verified_at', 'latitude', 'longitude',
        'manager_user_id', 'mobile_primary', 'mobile_secondary', 'next_of_kin', 'organization_id',
        'preferred_language', 'push_token', 'site_id', 'slack_id', 'source', 'teams_id', 'title',
        'updated_at', 'updated_by', 'user_id', 'uuid', 'verification_status', 'whatsapp',
    ],
    'bcms_corrective_actions' => [
        'acceptance_expires_on', 'acceptance_rationale', 'accepted_at', 'accepted_by',
        'carried_at', 'carried_to_occurrence_id', 'completed_at', 'completed_by', 'created_at',
        'created_by', 'deleted_at', 'description', 'due_date', 'erm_issue_id', 'finding_id', 'id',
        'iso_clause_ref', 'organization_id', 'owner_id', 'priority', 'reference', 'status',
        'title', 'updated_at', 'updated_by', 'uuid', 'verification_evidence_id',
        'verification_note', 'verified_at', 'verified_by',
    ],
    'bcms_data_sets' => [
        'classification', 'code', 'contains_personal_data', 'created_at', 'created_by',
        'deleted_at', 'external_ref', 'id', 'is_active', 'name', 'organization_id',
        'primary_application_id', 'residency_country', 'updated_at', 'updated_by',
    ],
    'bcms_dependencies' => [
        'alternative_available', 'assessment_id', 'created_at', 'criticality', 'dependable_id',
        'dependable_type', 'dependency_type', 'external_ref', 'id', 'organization_id',
        'recovery_notes', 'single_point_of_failure', 'updated_at',
    ],
    'bcms_dr_systems' => [
        'application_id', 'backup_frequency', 'created_at', 'created_by', 'deleted_at',
        'dr_site_id', 'dr_strategy', 'failover_runbook_plan_id', 'id', 'iso_clause_ref',
        'last_backup_verified_at', 'last_test_date', 'last_test_met_objectives',
        'last_test_rpo_actual_minutes', 'last_test_rto_actual_minutes', 'name', 'next_test_due',
        'organization_id', 'recovery_tier', 'replication_type', 'rpo_target_minutes',
        'rto_target_hours', 'updated_at', 'updated_by', 'uuid',
    ],
    'bcms_dr_tests' => [
        'created_at', 'created_by', 'dr_system_id', 'evidence', 'id', 'iso_clause_ref', 'issues',
        'met_objectives', 'notes', 'occurrence_id', 'organization_id', 'rollback_required',
        'rpo_actual_minutes', 'rto_actual_minutes', 'test_date', 'test_type', 'updated_at',
        'updated_by',
    ],
    'bcms_equipment' => [
        'code', 'created_at', 'created_by', 'deleted_at', 'equipment_type', 'external_ref', 'id',
        'is_active', 'name', 'organization_id', 'quantity', 'site_id', 'updated_at', 'updated_by',
    ],
    'bcms_exercise_definitions' => [
        'blackout_overrides', 'business_unit_id', 'created_at', 'created_by',
        'daily_reminder_enabled', 'default_audience_rule', 'default_channel_set', 'deleted_at',
        'distribution_mode', 'duration_minutes', 'exercise_programme_id', 'exercise_type_id',
        'facilitator_id', 'frequency_per_year', 'generation_log', 'id', 'iso_clause_ref',
        'lead_time_days', 'mandatory', 'min_notice_days', 'name', 'objectives', 'organization_id',
        'owner_id', 'preferred_window', 'process_ids', 'readiness_gating', 'regulatory_drivers',
        'reminder_mode', 'reminder_send_time', 'scenario_id', 'site_id', 'status', 'unannounced',
        'updated_at', 'updated_by', 'uuid',
    ],
    'bcms_exercise_injects' => [
        'ai_generated', 'content', 'created_at', 'delivery_channel', 'id', 'occurrence_id',
        'organization_id', 'release_offset_minutes', 'released_at', 'released_by', 'sequence',
        'target_rule', 'title', 'updated_at',
    ],
    'bcms_exercise_occurrences' => [
        'actual_end', 'actual_start', 'blocking_tasks_open', 'cancellation_reason', 'created_at',
        'created_by', 'definition_id', 'deleted_at', 'facilitator_id', 'id', 'iso_clause_ref',
        'location', 'organization_id', 'originally_scheduled_date', 'outcome',
        'readiness_complete', 'reschedule_count', 'scheduled_date', 'scheduled_end',
        'scheduled_start', 'sequence_no', 'site_id', 'status', 'updated_at', 'updated_by', 'uuid',
    ],
    'bcms_exercise_participants' => [
        'attendance_status', 'business_unit_id', 'check_in_method', 'checked_in_at', 'contact_id',
        'created_at', 'id', 'invitation_status', 'occurrence_id', 'organization_id', 'role',
        'updated_at', 'user_id',
    ],
    'bcms_exercise_programmes' => [
        'approved_at', 'approved_by', 'created_at', 'created_by', 'deleted_at', 'id',
        'iso_clause_ref', 'name', 'organization_id', 'programme_id', 'status', 'total_completed',
        'total_planned', 'updated_at', 'updated_by', 'uuid', 'year',
    ],
    'bcms_exercise_scores' => [
        'commentary', 'created_at', 'evaluator_id', 'evidence_file_id', 'id', 'objective_id',
        'objective_text', 'occurrence_id', 'organization_id', 'score', 'updated_at',
    ],
    'bcms_exercise_timeline' => [
        'content', 'created_at', 'entry_type', 'id', 'logged_at', 'logged_by', 'metadata',
        'occurrence_id', 'organization_id', 'updated_at',
    ],
    'bcms_exercise_types' => [
        'cadence_clause_ref', 'code', 'created_at', 'created_by', 'default_duration_minutes',
        'default_frequency_per_year', 'default_lead_time_days', 'deleted_at', 'description', 'id',
        'is_active', 'is_system_default', 'ladder_level', 'name', 'objectives_template',
        'organization_id', 'readiness_template_id', 'updated_at', 'updated_by',
    ],
    'bcms_findings' => [
        'aar_id', 'affected_business_unit_id', 'affected_plan_id', 'affected_process_id',
        'ai_generated', 'call_tree_test_id', 'classification', 'closed_at', 'created_at',
        'created_by', 'deleted_at', 'description', 'dr_test_id', 'erm_issue_id', 'id',
        'incident_id', 'iso_clause_ref', 'management_review_id', 'organization_id', 'raised_at',
        'raised_by', 'reference', 'root_cause', 'severity', 'source', 'status', 'updated_at',
        'updated_by', 'uuid',
    ],
    'bcms_incident_log' => [
        'attachments', 'content', 'created_at', 'entry_type', 'id', 'incident_id', 'logged_at',
        'logged_by', 'organization_id', 'supersedes_entry_id', 'updated_at',
    ],
    'bcms_incident_tasks' => [
        'completed_at', 'created_at', 'description', 'due_at', 'id', 'incident_id',
        'organization_id', 'owner_id', 'priority', 'status', 'title', 'updated_at',
    ],
    'bcms_incidents' => [
        'activation_level', 'business_unit_id', 'cbn_reference', 'closed_at', 'created_at',
        'created_by', 'currency', 'declared_at', 'declared_by', 'deleted_at', 'detected_at',
        'erm_loss_event_id', 'estimated_impact_minor', 'id', 'impacted_processes', 'incident_type',
        'is_exercise', 'is_reportable', 'iso_clause_ref', 'occurrence_id', 'organization_id',
        'reference', 'regulator_notified_at', 'reporting_due_at', 'severity', 'site_id', 'status',
        'title', 'updated_at', 'updated_by', 'uuid',
    ],
    'bcms_management_reviews' => [
        'approved_at', 'approved_by', 'attendees', 'chaired_by', 'created_at', 'created_by',
        'decisions', 'deleted_at', 'discussion', 'held_on', 'id', 'inputs', 'inputs_captured_at',
        'iso_clause_ref', 'organization_id', 'programme_id', 'reference', 'status', 'title',
        'updated_at', 'updated_by', 'uuid',
    ],
    'bcms_maturity_assessments' => [
        'assessed_at', 'assessed_by', 'created_at', 'created_by', 'id', 'method_version',
        'organization_id', 'overall_score', 'programme_id', 'trigger', 'updated_at', 'uuid',
    ],
    'bcms_maturity_scores' => [
        'assessment_id', 'clause_group', 'created_at', 'evidence_count', 'evidence_summary',
        'expected_count', 'id', 'organization_id', 'rationale', 'score', 'updated_at',
    ],
    'bcms_notification_deliveries' => [
        'address', 'alert_id', 'attempts', 'channel', 'cost_minor', 'created_at', 'currency',
        'delivered_at', 'failed_reason', 'id', 'organization_id', 'provider',
        'provider_message_id', 'raw_response', 'read_at', 'recipient_contact_id',
        'reminder_schedule_id', 'sent_at', 'status', 'updated_at',
    ],
    'bcms_objectives' => [
        'baseline_captured_at', 'baseline_value', 'created_at', 'created_by', 'deleted_at',
        'description', 'id', 'iso_clause_ref', 'key_risk_indicator_id', 'measure_description',
        'organization_id', 'owner_id', 'programme_id', 'status', 'target_date', 'target_unit',
        'target_value', 'title', 'updated_at', 'updated_by',
    ],
    'bcms_plan_activations' => [
        'activated_at', 'activated_by', 'activation_reason', 'created_at', 'deactivated_at', 'id',
        'incident_id', 'is_exercise', 'occurrence_id', 'organization_id', 'plan_id', 'updated_at',
    ],
    'bcms_plan_attestations' => [
        'attestation_type', 'attested_at', 'attested_by', 'attested_by_name', 'attested_by_role',
        'created_at', 'id', 'ip_address', 'iso_clause_ref', 'organization_id', 'period_year',
        'plan_id', 'statement', 'updated_at',
    ],
    'bcms_plan_sections' => [
        'ai_generated', 'body', 'created_at', 'id', 'is_overridden', 'last_verified_at',
        'needs_review', 'organization_id', 'plan_id', 'section_key', 'sort_order',
        'source_binding', 'source_fingerprint', 'title', 'updated_at',
    ],
    'bcms_plans' => [
        'ai_generated', 'approved_at', 'approver_id', 'business_unit_id', 'content', 'created_at',
        'created_by', 'deleted_at', 'distribution_rule', 'effective_from', 'id', 'iso_clause_ref',
        'next_review_date', 'offline_bundle_generated_at', 'offline_bundle_path',
        'organization_id', 'owner_id', 'plan_type', 'review_frequency_months', 'site_id', 'status',
        'supersedes_plan_id', 'title', 'updated_at', 'updated_by', 'uuid', 'version',
    ],
    'bcms_processes' => [
        'business_process_id', 'business_unit_id', 'category', 'code', 'created_at', 'created_by',
        'critical_service_justification', 'criticality_tier', 'deleted_at', 'description', 'id',
        'is_critical_service', 'iso_clause_ref', 'name', 'organization_id', 'owner_id',
        'parent_process_id', 'regulatory_flags', 'status', 'updated_at', 'updated_by', 'uuid',
    ],
    'bcms_programme_obligations' => [
        'applicability_note', 'applies', 'cadence', 'cadence_per_year', 'clause_ref', 'created_at',
        'created_by', 'how_satisfied', 'id', 'organization_id', 'owner_id', 'programme_id',
        'updated_at', 'updated_by',
    ],
    'bcms_programme_scope' => [
        'created_at', 'created_by', 'id', 'in_scope', 'organization_id', 'programme_id',
        'rationale', 'scopable_id', 'scopable_type', 'updated_at',
    ],
    'bcms_programmes' => [
        'approved_at', 'approved_by', 'board_attested_at', 'board_attested_by', 'created_at',
        'created_by', 'deleted_at', 'id', 'interested_parties', 'iso_clause_ref', 'name',
        'organization_id', 'out_of_scope_statement', 'owner_id', 'policy_plan_id',
        'scope_statement', 'status', 'updated_at', 'updated_by', 'uuid', 'year',
    ],
    'bcms_raci_assignments' => [
        'assignable_id', 'assignable_type', 'created_at', 'created_by', 'id', 'note',
        'organization_id', 'raci_role', 'updated_at', 'user_id',
    ],
    'bcms_readiness_tasks' => [
        'completed_at', 'completed_by', 'created_at', 'description', 'due_date', 'due_offset_days',
        'evidence_file_id', 'id', 'is_blocking', 'occurrence_id', 'organization_id',
        'overridden_at', 'overridden_by', 'override_reason', 'owner_id', 'status',
        'template_task_id', 'title', 'updated_at',
    ],
    'bcms_readiness_template_tasks' => [
        'created_at', 'default_owner_role', 'description', 'due_offset_days', 'id', 'is_blocking',
        'organization_id', 'requires_evidence', 'sort_order', 'template_id', 'title', 'updated_at',
    ],
    'bcms_readiness_templates' => [
        'code', 'created_at', 'created_by', 'deleted_at', 'description', 'id', 'is_active',
        'is_system_default', 'name', 'organization_id', 'updated_at', 'updated_by',
    ],
    'bcms_reminder_schedules' => [
        'audience_rule', 'channel_set', 'created_at', 'day_offset', 'dispatched_at', 'id',
        'idempotency_key', 'mode', 'occurrence_id', 'organization_id', 'recipient_count',
        'send_at', 'skip_reason', 'status', 'template_key', 'updated_at',
    ],
    'bcms_saved_group_members' => [
        'contact_id', 'created_at', 'group_id', 'id', 'organization_id', 'updated_at',
    ],
    'bcms_saved_groups' => [
        'created_at', 'created_by', 'deleted_at', 'description', 'id', 'is_dynamic', 'name',
        'organization_id', 'rule', 'updated_at', 'updated_by', 'uuid',
    ],
    'bcms_scenarios' => [
        'ai_generated', 'category', 'code', 'created_at', 'created_by', 'deleted_at', 'id',
        'is_active', 'is_system_default', 'ladder_level_min', 'name', 'narrative',
        'organization_id', 'regulatory_drivers', 'suggested_injects', 'suggested_objectives',
        'summary', 'updated_at', 'updated_by',
    ],
    'bcms_settings' => [
        'ai_capabilities', 'ai_enabled', 'alert_currency', 'contact_verification_days',
        'created_at', 'created_by', 'critical_service_rto_ceiling_hours', 'default_channel_set',
        'default_lead_time_days', 'default_reminder_mode', 'escalation_day_offset',
        'exercise_simulation_default', 'id', 'impact_intolerable_score', 'life_safety_channel_set',
        'organization_id', 'quiet_hours_end', 'quiet_hours_start', 'reminder_send_time',
        'require_dual_approval_for_live', 'timezone', 'updated_at', 'updated_by',
    ],
    'bcms_sites' => [
        'address', 'business_unit_id', 'city', 'code', 'country', 'created_at', 'created_by',
        'deleted_at', 'external_ref', 'headcount', 'id', 'is_active', 'is_recovery_site',
        'latitude', 'longitude', 'name', 'organization_id', 'recovery_site_id', 'site_type',
        'state', 'updated_at', 'updated_by', 'uuid',
    ],
    'bcms_strategies' => [
        'approval_status', 'approved_at', 'approved_by', 'assessed_against_assessment_id',
        'cost_estimate_minor', 'created_at', 'created_by', 'currency', 'deleted_at', 'description',
        'gap_vs_required_hours', 'id', 'is_selected', 'iso_clause_ref', 'organization_id',
        'process_id', 'resource_requirements', 'rto_achievable_hours', 'selection_rationale',
        'strategy_type', 'title', 'updated_at', 'updated_by', 'uuid',
    ],
    'bcms_training_curricula' => [
        'code', 'created_at', 'created_by', 'deleted_at', 'description', 'frequency_months', 'id',
        'is_active', 'is_mandatory', 'is_system_default', 'iso_clause_ref', 'modules', 'name',
        'organization_id', 'pass_mark', 'requires_assessment', 'target_roles', 'updated_at',
        'updated_by',
    ],
    'bcms_training_records' => [
        'assessor_id', 'certificate_id', 'competency_assessed', 'completed_at', 'created_at',
        'created_by', 'curriculum_id', 'deleted_at', 'id', 'iso_clause_ref', 'next_due_date',
        'occurrence_id', 'organization_id', 'score', 'updated_at', 'updated_by', 'user_id',
    ],
];
