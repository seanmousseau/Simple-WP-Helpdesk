---
title: Categories
nav_order: 7
---

# Categories

Categories are **departments or topics** you can assign to tickets — for example *Billing*, *Technical Support*, *Account Access*, *Feature Requests*. They give you a way to filter the ticket list, route tickets automatically to the right technician, and report on volume per department.

Categories are a standard WordPress hierarchical taxonomy on the `helpdesk_ticket` custom post type. They behave like blog post categories — including parent/child relationships.

---

## Setting up categories

1. Go to **Tickets → Categories** in the WordPress dashboard.
2. Fill in **Name** (and optionally **Slug**, **Parent Category**, **Description**).
3. Click **Add New Category**.

Repeat for each department. You can build a hierarchy by setting a parent on child categories — for example, **Technical Support → Network Issues**, **Technical Support → Software Bugs**.

---

## Assigning a category to a ticket

### From the admin editor

1. Open the ticket in the WordPress admin.
2. In the **Categories** meta box on the right, check one or more categories.
3. Click **Update**.

A ticket can have multiple categories.

### Automatically on submission

Use auto-assignment rules (see below) to assign a category implicitly via the routing flow — for example, every ticket routed to your billing technician gets the *Billing* category.

Optionally, you can extend the submission form to show a category picker to clients using the `swh_submission_data` filter and a small theme tweak. This isn't built in by default — see the [Hooks Reference](hooks) for the filter signature.

---

## Filtering tickets by category

The ticket list (**Tickets** menu) has a **Category** filter dropdown above the table. Pick a category to narrow the list. Combine with status/priority/assignee filters as needed.

Each ticket row also shows its category column. Click any category label in a row to filter the list to that category.

---

## Auto-assignment rules

Categories are how the auto-assignment system routes tickets to the right technician.

1. Go to **Tickets → Settings → Assignment & Routing**.
2. In the **Auto-Assignment Rules** section, click **Add Rule**.
3. Pick a **Category** and a **Technician**.
4. Save.

When a new ticket is created and matches one of the rule's categories, it's automatically assigned to that technician. **First matching rule wins** — if you have two rules and a ticket matches both, the technician from the rule listed first gets it.

If no rule matches, the **Default Assignee** (also configured on that tab) is used. If there's no default assignee either, the **Fallback Alert Email** address gets the notification.

---

## Reporting by category

The current Reports dashboard doesn't have a category-specific breakdown built in. You can use the standard WordPress admin search and filter combinations on the ticket list to count tickets per category for now.

Per-category reports are on the v4.x roadmap — see the [project roadmap](https://github.com/seanmousseau/Simple-WP-Helpdesk/blob/main/docs/internal/roadmap.md) for status.

---

## Tips

- **Keep your category list short.** Three to seven top-level categories works well; more than that and operators struggle to pick consistently.
- **Use the hierarchy when it helps reporting**, not just for organization. Sub-categories are useful when you want to know "how much of our Technical Support volume is Network Issues vs Software Bugs" — but only if you'll actually use that breakdown.
- **Auto-assignment rules apply at ticket creation only**, not on later category changes. If you change a ticket's category after submission, the assignee doesn't change automatically.

---

## See also

- [Usage Guide → Categories / Departments](usage#categories--departments)
- [Configuration → Assignment & Routing](configuration#assignment--routing)
