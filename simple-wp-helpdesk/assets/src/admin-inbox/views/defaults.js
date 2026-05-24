export const DEFAULT_VIEWS = [
	{
		key: 'all',
		label: 'All',
		filters: {},
		sort: 'last_reply_desc',
	},
	{
		key: 'unassigned',
		label: 'Unassigned',
		filters: { status: [ 'open', 'in_progress' ], assignee: 'none' },
		sort: 'last_reply_desc',
	},
	{
		key: 'my_open',
		label: 'My Open',
		filters: { status: [ 'open', 'in_progress' ], assignee: 'me' },
		sort: 'last_reply_desc',
	},
	{
		key: 'high_priority',
		label: 'High priority',
		filters: {
			status: [ 'open', 'in_progress' ],
			priority: [ 'high', 'urgent' ],
		},
		sort: 'priority_desc',
	},
	{
		key: 'resolved_this_week',
		label: 'Resolved this week',
		filters: { status: 'resolved', resolved_at: '7d' },
		sort: 'resolved_at_desc',
	},
];
