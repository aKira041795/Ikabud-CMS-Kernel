---
name: iterative-task-execution
description: Loop through project requirements until done, maintaining scope and tracking progress
applyTo: "**/*"
---

# Iterative Task Execution

## Workflow
When given a multi-step requirement or a goal that needs iterative refinement:

1. **Break down** the requirement into specific, actionable items
2. **Create a todo list** with all items (use `manage_todo_list`)
3. **Mark ONE item** as `in-progress`, work on it exclusively
4. **Complete it** — verify syntax (`php -l`), check logs if needed, commit
5. **Mark it completed** immediately
6. **Move to next item** and repeat
7. **Loop** until all items are done or the requirement is met

## Scope discipline
- Stay within the defined requirement — don't add extra features or refactor unrelated code
- If new sub-tasks emerge, add them to the todo list before starting them
- If a task is blocked (needs user input), save progress and ask
- Don't redesign architecture mid-stream unless the requirement demands it

## Output style
- Keep messages concise: what was done, what's next
- No long explanations — show the code change, not the reasoning
- After each item, update the todo list and show the next step
- When all items are done, call `task_complete` with a brief summary

## Verification per step
- PHP: `php -l <file>` after edits
- SQL: check for module DB DENIED in logs if query fails
- Templates: check app.log for DiSyL strict warnings
- Git: commit per logical change (not per file)
