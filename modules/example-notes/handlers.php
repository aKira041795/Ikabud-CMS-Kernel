<?php
/**
 * Example Notes Module — Handlers
 *
 * Each public function here handles exactly one route, declared in routes.php.
 * Handler signature: function name(array $params = []): void
 *
 * Key patterns demonstrated:
 *  1. Auth guard via enCtx()->requireAnyRole()
 *  2. Database access via enDb() — never app()->db() directly
 *  3. Input reading via enInput() — never $_POST/$_GET directly
 *  4. Rendering via enRender() — scoped to this module's templates
 *  5. Event emission via app()->events()->fire() after state changes
 *  6. JSON API responses with proper HTTP status codes
 *
 * @see docs/module-development-guide.md — Handlers section
 * @see docs/module-quickstart.md — Step 5
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// ── Admin Page Handlers ──────────────────────────────────────────

/**
 * GET /admin/example-notes
 * Lists all notes in the admin panel.
 */
function pageExampleNotesList(array $params = []): void
{
    enCtx()->requireAnyRole('admin', 'supervisor');

    $notes = enDb()->query('SELECT * FROM en_notes ORDER BY created_at DESC')->fetchAll(\PDO::FETCH_ASSOC);

    echo enRender('pages/list.disyl', [
        'page_title' => 'Example Notes',
        'notes'      => $notes,
    ]);
}

/**
 * GET /admin/example-notes/new
 * Renders the new note form.
 */
function pageExampleNotesNew(array $params = []): void
{
    enCtx()->requireAnyRole('admin', 'supervisor');

    echo enRender('pages/new.disyl', [
        'page_title' => 'New Note',
    ]);
}

/**
 * GET /admin/example-notes/{id}
 * Views/edits a single note.
 */
function pageExampleNotesView(array $params = []): void
{
    enCtx()->requireAnyRole('admin', 'supervisor');

    $id   = (int)($params['id'] ?? 0);
    $note = enDb()
        ->prepare('SELECT * FROM en_notes WHERE id = :id')
        ->execute([':id' => $id])
        ->fetch(\PDO::FETCH_ASSOC);

    if (!$note) {
        http_response_code(404);
        echo enRender('pages/list.disyl', [
            'page_title' => 'Example Notes',
            'notes'      => [],
            'flash_error' => 'Note not found.',
        ]);
        return;
    }

    echo enRender('pages/view.disyl', [
        'page_title' => 'Edit Note',
        'note'       => $note,
    ]);
}

// ── API Handlers ─────────────────────────────────────────────────

/**
 * POST /api/v1/example-notes/notes
 * Creates a new note. Returns JSON {ok, id} or {ok, error}.
 */
function apiExampleNotesCreate(array $params = []): void
{
    header('Content-Type: application/json');
    enCtx()->requireAnyRole('admin', 'supervisor');

    $input = enInput();
    $title = trim((string)($input['title'] ?? ''));
    $body  = trim((string)($input['body'] ?? ''));

    // Validate at the system boundary
    if ($title === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Title is required']);
        return;
    }

    $user = enCtx()->user();
    $db   = enDb();

    $stmt = $db->prepare(
        'INSERT INTO en_notes (title, body, created_by) VALUES (:title, :body, :created_by)'
    );
    $stmt->execute([
        ':title'      => $title,
        ':body'       => $body,
        ':created_by' => (string)($user['email'] ?? 'unknown'),
    ]);
    $newId = (int)$db->lastInsertId();

    // Emit event so other modules can react (e.g. audit, notifications)
    app()->events()->fire('example-notes.note.created', [
        'id'         => $newId,
        'title'      => $title,
        'created_by' => (string)($user['email'] ?? 'unknown'),
    ]);

    echo json_encode(['ok' => true, 'id' => $newId]);
}

/**
 * POST /api/v1/example-notes/notes/{id}
 * Updates an existing note.
 */
function apiExampleNotesUpdate(array $params = []): void
{
    header('Content-Type: application/json');
    enCtx()->requireAnyRole('admin', 'supervisor');

    $id    = (int)($params['id'] ?? 0);
    $input = enInput();
    $title = trim((string)($input['title'] ?? ''));
    $body  = trim((string)($input['body'] ?? ''));

    if ($id <= 0 || $title === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Valid id and title are required']);
        return;
    }

    $affected = enDb()
        ->prepare('UPDATE en_notes SET title = :title, body = :body WHERE id = :id')
        ->execute([':title' => $title, ':body' => $body, ':id' => $id]);

    echo json_encode(['ok' => $affected > 0]);
}

/**
 * POST /api/v1/example-notes/notes/{id}/delete
 * Deletes a note by id.
 */
function apiExampleNotesDelete(array $params = []): void
{
    header('Content-Type: application/json');
    enCtx()->requireAnyRole('admin');

    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Valid id required']);
        return;
    }

    $affected = enDb()
        ->prepare('DELETE FROM en_notes WHERE id = :id')
        ->execute([':id' => $id]);

    if ($affected > 0) {
        app()->events()->fire('example-notes.note.deleted', ['id' => $id]);
    }

    echo json_encode(['ok' => $affected > 0]);
}
