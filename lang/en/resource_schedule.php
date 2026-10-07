<?php

// Room/equipment schedule and blocks (Settings › Resources).
return [
    'title'             => 'Schedule and blocks',
    'breadcrumb'        => 'Schedule and blocks',
    'back'              => 'Back to Resources',
    'action'            => 'Schedule and blocks',
    'intro'             => 'Set when this room/equipment can be booked in the Schedule. With no active day there is no time restriction; blocks always apply.',
    'active_days_zero'  => 'No active day',
    'active_days_one'   => '1 active day',
    'active_days_other' => ':count active days',
    'schedule_title'    => 'Availability hours',
    'day_toggle'        => 'Available on :day',
    'day_off'           => 'Unavailable on this day.',
    'add_range'         => 'Range',
    'add_range_aria'    => 'Add a time range on :day',
    'remove_range'      => 'Remove time range',
    'from'              => 'From',
    'until'             => 'to',
    'range_start'       => 'Range start on :day',
    'range_end'         => 'Range end on :day',
    'no_ranges'         => 'No time range. Use "+ Range" to add one.',
    'save'              => 'Save schedule',
    'saving'            => 'Saving…',
    'saved'             => 'Schedule saved.',
    'save_error'        => 'Could not save the schedule.',
    'conn_error'        => 'Connection error. Try again.',

    'blocks_title'      => 'Blocks',
    'blocks_hint'       => 'Periods when the room/equipment cannot be booked (maintenance, holiday…).',
    'block_add'         => 'Add block',
    'block_type'        => 'Type',
    'block_starts'      => 'Start',
    'block_ends'        => 'End',
    'block_reason'      => 'Reason',
    'block_reason_ph'   => 'Reason (optional)',
    'block_save'        => 'Save block',
    'block_cancel'      => 'Cancel',
    'block_remove'      => 'Remove block',
    'block_remove_aria' => 'Remove block from :start to :end',
    'block_confirm'     => 'Remove this block? This cannot be undone.',
    'no_blocks'         => 'No upcoming blocks.',
    'block_error'       => 'Could not save the block.',

    'block_types' => [
        'absence' => 'Unavailable',
        'holiday' => 'Holiday',
        'meeting' => 'Maintenance',
        'other'   => 'Other',
    ],

    'resource_types' => [
        'room'      => 'Room',
        'equipment' => 'Equipment',
        'other'     => 'Other',
    ],

    // Schedule validation (sync)
    'range_invalid'   => ':day: the range end (:end) must be after its start (:start).',
    'range_duplicate' => ':day: two ranges start at :start.',
    'range_overlap'   => ':day: ranges :first and :second overlap.',
];
