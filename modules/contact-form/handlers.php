<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function contactFormJsonOk(array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function contactFormJsonError(string $message, int $status = 400, array $extra = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function contactFormRedirectTo(string $path): never
{
    header('Location: ' . contactFormPath($path));
    exit;
}

function contactFormRenderNotFound(string $title = 'Not Found'): void
{
    http_response_code(404);
    if (function_exists('cmsRender')) {
        echo cmsRender('pages/404.disyl', ['page_title' => $title]);
        return;
    }

    echo $title;
}

function contactFormValidateFormInput(array $input, ?int $existingId = null): array
{
    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        return ['error' => (string) ($schema['message'] ?? 'Contact form schema is not ready yet.')];
    }

    $name = contactFormLimit(trim((string) ($input['name'] ?? '')), 255);
    if ($name === '') {
        return ['error' => 'Form name is required.'];
    }

    $slugSource = trim((string) ($input['slug'] ?? ''));
    $slug = contactFormSlugify($slugSource !== '' ? $slugSource : $name);
    if ($slug === '') {
        return ['error' => 'A valid slug is required.'];
    }

    $successMessage = contactFormLimit(trim((string) ($input['success_message'] ?? '')), 5000);
    $submitLabel = contactFormLimit(trim((string) ($input['submit_label'] ?? '')), 100);
    $status = trim((string) ($input['status'] ?? 'active'));
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    $captchaEnabled = contactFormBoolish($input['captcha_enabled'] ?? '1') ? 1 : 0;

    try {
        $stmt = contactFormDb()->prepare('SELECT id FROM contact_forms WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row && (int) ($row['id'] ?? 0) !== (int) $existingId) {
            return ['error' => 'That slug is already in use.'];
        }
    } catch (Throwable $e) {
        return ['error' => 'Unable to validate the form slug right now.'];
    }

    $confirmationRules = [];
    $notificationRules = [];
    if ($existingId !== null && $existingId > 0) {
        $fields = contactFormGetFieldsForForm($existingId);

        $confirmationValidation = contactFormValidateConfirmationRulesInput($input['confirmation_rules_json'] ?? '[]', $fields);
        if (!empty($confirmationValidation['error'])) {
            return ['error' => (string) $confirmationValidation['error']];
        }

        $notificationValidation = contactFormValidateNotificationRulesInput($input['notification_rules_json'] ?? '[]', $fields);
        if (!empty($notificationValidation['error'])) {
            return ['error' => (string) $notificationValidation['error']];
        }

        $confirmationRules = is_array($confirmationValidation['data'] ?? null) ? $confirmationValidation['data'] : [];
        $notificationRules = is_array($notificationValidation['data'] ?? null) ? $notificationValidation['data'] : [];
    }

    return [
        'data' => [
            'name' => $name,
            'slug' => $slug,
            'success_message' => $successMessage,
            'submit_label' => $submitLabel,
            'confirmation_rules' => $confirmationRules,
            'notification_rules' => $notificationRules,
            'captcha_enabled' => $captchaEnabled,
            'status' => $status,
        ],
    ];
}

function contactFormHydrateFormFromInput(array $input, array $fallback = []): array
{
    $form = array_merge(contactFormFormDefaults(), $fallback);
    $form['name'] = contactFormLimit(trim((string) ($input['name'] ?? $form['name'] ?? '')), 255);
    $form['slug'] = contactFormSlugify((string) ($input['slug'] ?? $form['slug'] ?? $form['name']));
    $form['success_message'] = contactFormLimit(trim((string) ($input['success_message'] ?? $form['success_message'] ?? '')), 5000);
    $form['submit_label'] = contactFormLimit(trim((string) ($input['submit_label'] ?? $form['submit_label'] ?? '')), 100);
    $form['confirmation_rules'] = contactFormNormalizeConfirmationRules($input['confirmation_rules_json'] ?? ($form['confirmation_rules'] ?? []));
    $form['confirmation_rules_json'] = contactFormRulesToJson(is_array($form['confirmation_rules'] ?? null) ? $form['confirmation_rules'] : []);
    $form['notification_rules'] = contactFormNormalizeNotificationRules($input['notification_rules_json'] ?? ($form['notification_rules'] ?? []));
    $form['notification_rules_json'] = contactFormRulesToJson(is_array($form['notification_rules'] ?? null) ? $form['notification_rules'] : []);
    $form['captcha_enabled'] = contactFormBoolish($input['captcha_enabled'] ?? ($form['captcha_enabled'] ?? 1)) ? 1 : 0;
    $form['status'] = in_array((string) ($input['status'] ?? $form['status'] ?? 'active'), ['active', 'inactive'], true)
        ? (string) ($input['status'] ?? $form['status'])
        : 'active';

    return $form;
}

function contactFormSubmissionError(string $message, array $fieldErrors = []): array
{
    $payload = ['error' => $message];

    $normalizedFieldErrors = [];
    foreach ($fieldErrors as $fieldName => $fieldMessage) {
        $fieldName = trim((string) $fieldName);
        $fieldMessage = trim((string) $fieldMessage);
        if ($fieldName === '' || $fieldMessage === '') {
            continue;
        }

        $normalizedFieldErrors[$fieldName] = $fieldMessage;
    }

    if ($normalizedFieldErrors !== []) {
        $payload['field_errors'] = $normalizedFieldErrors;
    }

    return $payload;
}

function contactFormDecodeJsonArrayInput(mixed $value, string $label): array
{
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return ['data' => []];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return ['error' => 'Invalid ' . $label . ' configuration.'];
        }

        return ['data' => $decoded];
    }

    if (is_array($value)) {
        return ['data' => $value];
    }

    if ($value === null || $value === '') {
        return ['data' => []];
    }

    return ['error' => 'Invalid ' . $label . ' configuration.'];
}

function contactFormValidateConditionSet(array $conditions, array $availableFields, string $contextLabel): ?string
{
    if ($conditions === []) {
        return 'Add at least one condition to each ' . $contextLabel . ' rule.';
    }

    foreach ($conditions as $condition) {
        $sourceFieldId = (int) ($condition['field_id'] ?? 0);
        if ($sourceFieldId <= 0 || !isset($availableFields[$sourceFieldId])) {
            return 'One or more ' . $contextLabel . ' rules reference a field that no longer exists.';
        }
    }

    return null;
}

function contactFormValidateConfirmationRulesInput(mixed $value, array $fields): array
{
    $decoded = contactFormDecodeJsonArrayInput($value, 'confirmation workflow');
    if (!empty($decoded['error'])) {
        return ['error' => (string) $decoded['error']];
    }

    $rawRules = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    $rules = contactFormNormalizeConfirmationRules($rawRules);
    if ($rawRules !== [] && $rules === []) {
        return ['error' => 'Add at least one valid confirmation rule or clear the confirmation workflow.'];
    }

    $availableFields = contactFormAvailableConditionFields($fields);
    foreach ($rules as $rule) {
        $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [];
        $conditionError = contactFormValidateConditionSet($conditions, $availableFields, 'confirmation');
        if ($conditionError !== null) {
            return ['error' => $conditionError];
        }

        $type = trim((string) ($rule['type'] ?? 'message'));
        if ($type === 'message' && trim((string) ($rule['message'] ?? '')) === '') {
            return ['error' => 'Each confirmation message rule needs a message.'];
        }

        if ($type === 'redirect') {
            $redirectTarget = trim((string) ($rule['redirect_url'] ?? ''));
            if ($redirectTarget === '') {
                return ['error' => 'Each redirect rule needs a redirect URL.'];
            }

            try {
                kernel_validate_redirect_target($redirectTarget);
            } catch (Throwable $e) {
                return ['error' => 'One or more redirect targets are invalid.'];
            }
        }
    }

    return ['data' => $rules];
}

function contactFormValidateNotificationRulesInput(mixed $value, array $fields): array
{
    $decoded = contactFormDecodeJsonArrayInput($value, 'notification routing');
    if (!empty($decoded['error'])) {
        return ['error' => (string) $decoded['error']];
    }

    $rawRules = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    $rules = contactFormNormalizeNotificationRules($rawRules);
    if ($rawRules !== [] && $rules === []) {
        return ['error' => 'Add at least one valid notification routing rule or clear the routing builder.'];
    }

    $availableFields = contactFormAvailableConditionFields($fields);
    foreach ($rules as $rule) {
        $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [];
        $conditionError = contactFormValidateConditionSet($conditions, $availableFields, 'notification');
        if ($conditionError !== null) {
            return ['error' => $conditionError];
        }

        $recipientEmail = trim((string) ($rule['recipient_email'] ?? ''));
        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Each notification routing rule needs a valid recipient email address.'];
        }
    }

    return ['data' => $rules];
}

function contactFormValidateConditionalLogicInput(array $input, int $formId, ?int $existingFieldId = null): array
{
    $enabled = contactFormBoolish($input['conditional_logic_enabled'] ?? '');

    // When conditional logic is disabled, skip rule parsing entirely.
    // The form always renders a blank template rule row whose operator select is
    // non-empty ('equals'), which would otherwise falsely trigger the
    // "Choose a source field" error and block the save.
    if (!$enabled) {
        return ['data' => contactFormConditionalLogicDefaults()];
    }

    $action = trim((string) ($input['conditional_logic_action'] ?? 'show'));
    if (!in_array($action, ['show', 'hide'], true)) {
        $action = 'show';
    }

    $match = trim((string) ($input['conditional_logic_match'] ?? 'all'));
    if (!in_array($match, ['all', 'any'], true)) {
        $match = 'all';
    }

    $fieldIds = is_array($input['conditional_logic_field_id'] ?? null) ? $input['conditional_logic_field_id'] : [];
    $operators = is_array($input['conditional_logic_operator'] ?? null) ? $input['conditional_logic_operator'] : [];
    $values = is_array($input['conditional_logic_value'] ?? null) ? $input['conditional_logic_value'] : [];
    $rowCount = max(count($fieldIds), count($operators), count($values));
    $rules = [];

    for ($index = 0; $index < $rowCount; $index++) {
        $fieldId = max(0, (int) ($fieldIds[$index] ?? 0));
        $operator = contactFormNormalizeConditionalOperator((string) ($operators[$index] ?? 'equals'));
        $value = contactFormLimit(trim((string) ($values[$index] ?? '')), 255);

        $rowHasAnyValue = $fieldId > 0 || trim((string) ($operators[$index] ?? '')) !== '' || $value !== '';
        if (!$rowHasAnyValue) {
            continue;
        }

        if ($fieldId <= 0) {
            return ['error' => 'Choose a source field for every conditional rule.'];
        }

        if ($existingFieldId !== null && $fieldId === $existingFieldId) {
            return ['error' => 'A field cannot depend on itself.'];
        }

        if (contactFormConditionalLogicOperatorUsesValue($operator) && $value === '') {
            return ['error' => 'Enter a comparison value for every conditional rule that requires one.'];
        }

        if (!contactFormConditionalLogicOperatorUsesValue($operator)) {
            $value = '';
        }

        $rules[] = [
            'field_id' => $fieldId,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    if ($rules === []) {
        return ['error' => 'Add at least one conditional rule or turn conditional logic off.'];
    }

    try {
        $stmt = contactFormDb()->prepare('SELECT id, field_type FROM contact_form_fields WHERE form_id = :form_id');
        $stmt->execute([':form_id' => $formId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['error' => 'Unable to validate conditional logic right now.'];
    }

    $available = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $available[(int) ($row['id'] ?? 0)] = trim((string) ($row['field_type'] ?? 'text'));
    }

    foreach ($rules as $rule) {
        $sourceFieldId = (int) ($rule['field_id'] ?? 0);
        if ($sourceFieldId <= 0 || !array_key_exists($sourceFieldId, $available)) {
            return ['error' => 'One or more conditional rules reference a field that no longer exists.'];
        }

        if (($available[$sourceFieldId] ?? '') === 'section') {
            return ['error' => 'Section headings cannot be used as conditional logic sources.'];
        }
    }

    return [
        'data' => [
            'enabled' => true,
            'action' => $action,
            'match' => $match,
            'rules' => $rules,
        ],
    ];
}

function contactFormValidateFieldInput(array $input, int $formId, ?int $existingFieldId = null): array
{
    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        return ['error' => (string) ($schema['message'] ?? 'Contact form schema is not ready yet.')];
    }

    $fieldType = trim((string) ($input['field_type'] ?? 'text'));
    if (!array_key_exists($fieldType, contactFormFieldTypeLabels())) {
        $fieldType = 'text';
    }

    $label = contactFormLimit(trim((string) ($input['label'] ?? '')), 255);
    if ($label === '') {
        return ['error' => 'Field label is required.'];
    }

    $nameSource = trim((string) ($input['name'] ?? ''));
    $name = contactFormFieldNameify($nameSource !== '' ? $nameSource : $label);
    if ($name === '') {
        return ['error' => 'A valid field name is required.'];
    }

    $placeholder = contactFormLimit(trim((string) ($input['placeholder'] ?? '')), 255);
    $helpText = contactFormLimit(trim((string) ($input['help_text'] ?? '')), 1000);
    $optionsText = contactFormNormalizeOptionsText(contactFormLimit((string) ($input['options_text'] ?? ''), 5000));
    // select and radio always require at least one option; checkbox may be empty (becomes a single agree/disagree toggle)
    if (in_array($fieldType, ['select', 'radio'], true) && $optionsText === '') {
        return ['error' => 'Select and Radio fields need at least one option.'];
    }
    if (!in_array($fieldType, contactFormFieldTypesWithOptions(), true)) {
        $optionsText = '';
    }

    $sortOrder = max(0, (int) ($input['sort_order'] ?? 0));
    $required = contactFormBoolish($input['required'] ?? '') ? 1 : 0;
    if (in_array($fieldType, ['hidden', 'section'], true)) {
        $required = 0;
    }

    try {
        if ($existingFieldId === null) {
            $baseName = $name;
            $counter = 2;
            while (true) {
                $stmt = contactFormDb()->prepare(
                    'SELECT id FROM contact_form_fields WHERE form_id = :form_id AND name = :name LIMIT 1'
                );
                $stmt->execute([':form_id' => $formId, ':name' => $name]);
                if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
                    break;
                }

                if ($counter > 99) {
                    return ['error' => 'Could not generate a unique field name.'];
                }

                $name = $baseName . '_' . $counter++;
            }
        } else {
            $stmt = contactFormDb()->prepare(
                'SELECT id FROM contact_form_fields WHERE form_id = :form_id AND name = :name LIMIT 1'
            );
            $stmt->execute([':form_id' => $formId, ':name' => $name]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && (int) ($row['id'] ?? 0) !== (int) $existingFieldId) {
                return ['error' => 'That field name is already in use on this form.'];
            }
        }
    } catch (Throwable $e) {
        return ['error' => 'Unable to validate the field name right now.'];
    }

    $conditionalLogic = contactFormConditionalLogicDefaults();
    if ($fieldType !== 'hidden') {
        $logicValidation = contactFormValidateConditionalLogicInput($input, $formId, $existingFieldId);
        if (!empty($logicValidation['error'])) {
            return ['error' => (string) $logicValidation['error']];
        }

        $conditionalLogic = contactFormNormalizeConditionalLogic($logicValidation['data'] ?? null);
    }

    return [
        'data' => [
            'field_type' => $fieldType,
            'label' => $label,
            'name' => $name,
            'placeholder' => $placeholder,
            'help_text' => $helpText,
            'options_text' => $optionsText,
            'conditional_logic' => $conditionalLogic,
            'required' => $required,
            'sort_order' => $sortOrder,
        ],
    ];
}

function contactFormNormalizeSubmissionStatus(string $status): string
{
    $status = contactFormCanonicalSubmissionStatus($status);
    return array_key_exists($status, contactFormSubmissionStatusMap()) ? $status : '';
}

function contactFormBuildSubmissionFilters(array $input): array
{
    $selectedFormId = max(0, (int) ($input['form_id'] ?? 0));
    $selectedStatus = contactFormNormalizeSubmissionStatus((string) ($input['status'] ?? ''));

    $clauses = [];
    $bind = [];
    if ($selectedFormId > 0) {
        $clauses[] = 's.form_id = :form_id';
        $bind[':form_id'] = $selectedFormId;
    }
    if ($selectedStatus !== '') {
        $clauses[] = 's.status = :status';
        $bind[':status'] = $selectedStatus;
    }

    return [
        'selected_form_id' => $selectedFormId,
        'selected_status' => $selectedStatus,
        'where_sql' => $clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '',
        'bind' => $bind,
    ];
}

function contactFormSubmissionPreview(string $formDataJson, string $fallback): string
{
    $decoded = json_decode($formDataJson, true);
    if (is_array($decoded) && $decoded !== []) {
        $parts = [];
        foreach (array_slice($decoded, 0, 3) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = trim((string) ($entry['label'] ?? $entry['name'] ?? 'Field'));
            $value = trim((string) ($entry['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $parts[] = $label . ': ' . $value;
        }

        if ($parts !== []) {
            return contactFormLimit(implode(' | ', $parts), 240);
        }
    }

    $fallback = trim($fallback);
    if ($fallback === '') {
        return 'No preview available.';
    }

    $fallback = preg_replace('/\s+/', ' ', $fallback) ?? $fallback;
    return contactFormLimit($fallback, 240);
}

function contactFormBuildSubmissionSummary(array $records): string
{
    $lines = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $label = trim((string) ($record['label'] ?? $record['name'] ?? 'Field'));
        $value = trim((string) ($record['value'] ?? ''));
        if ($value === '') {
            continue;
        }

        $lines[] = $label . ': ' . $value;
        if (count($lines) >= 8) {
            break;
        }
    }

    return contactFormLimit(implode("\n", $lines), 5000);
}

function contactFormBuildNotificationContent(array $records, string $sentFrom, ?array $form = null): string
{
    $intro = $form
        ? 'You have received a new submission for ' . contactFormEscape((string) ($form['name'] ?? 'your saved form')) . '.'
        : 'You have received a new contact form submission.';

    $rows = '';
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $label = contactFormEscape(trim((string) ($record['label'] ?? $record['name'] ?? 'Field')));
        $value = trim((string) ($record['value'] ?? ''));
        $valueHtml = nl2br(contactFormEscape($value), false);
        $rows .= <<<HTML
<tr>
    <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #374151; width: 120px; vertical-align: top;">{$label}</td>
    <td style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; color: #4b5563; white-space: pre-wrap; word-break: break-word;">{$valueHtml}</td>
</tr>
HTML;
    }

    if ($rows === '') {
        $rows = <<<HTML
<tr>
    <td style="padding: 12px 16px; color: #4b5563;">No values were recorded.</td>
</tr>
HTML;
    }

    $sentFromHtml = contactFormEscape($sentFrom);

    return <<<HTML
<p style="margin: 0 0 20px; color: #4b5563; font-size: 16px; line-height: 1.6;">{$intro}</p>
<table role="presentation" style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f9fafb; border-radius: 6px;">
    {$rows}
</table>
<p style="margin: 20px 0 0; color: #6b7280; font-size: 14px;"><em>Sent from: {$sentFromHtml}</em></p>
HTML;
}

function contactFormPrepareLegacySubmission(array $input): array
{
    $name = contactFormLimit(trim((string) ($input['name'] ?? '')), 255);
    $email = contactFormLimit(trim((string) ($input['email'] ?? '')), 255);
    $message = contactFormLimit(trim((string) ($input['message'] ?? '')), 5000);

    $fieldErrors = [];
    if ($name === '') {
        $fieldErrors['name'] = 'Name is required.';
    }

    if ($email === '') {
        $fieldErrors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = 'Please enter a valid email address.';
    }

    if ($message === '') {
        $fieldErrors['message'] = 'Message is required.';
    } elseif (strlen($message) < 10) {
        $fieldErrors['message'] = 'Message must be at least 10 characters.';
    }

    if ($fieldErrors !== []) {
        return contactFormSubmissionError('Please correct the highlighted fields and try again.', $fieldErrors);
    }

    return [
        'records' => [
            ['label' => 'Name', 'name' => 'name', 'type' => 'text', 'value' => $name],
            ['label' => 'Email', 'name' => 'email', 'type' => 'email', 'value' => $email],
            ['label' => 'Message', 'name' => 'message', 'type' => 'textarea', 'value' => $message],
        ],
        'name' => $name,
        'email' => $email,
        'message' => $message,
    ];
}

function contactFormPrepareDynamicSubmission(array $fields, array $input): array
{
    if ($fields === []) {
        return contactFormSubmissionError('This form has no fields yet.');
    }

    $records = [];
    $summaryName = '';
    $summaryEmail = '';
    $hasValue = false;
    $visibility = contactFormResolveFieldVisibility($fields, $input);
    $fieldErrors = [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $field = contactFormNormalizeFieldRow($field);
        if ($field['field_type'] === 'section') {
            continue;
        }

        $fieldId = (int) ($field['id'] ?? 0);
        $isVisible = $field['field_type'] === 'hidden'
            ? true
            : ($fieldId > 0 ? (bool) ($visibility[$fieldId] ?? true) : true);
        if (!$isVisible) {
            continue;
        }

        $rawValue = $input[$field['name']] ?? '';
        $normalizedArrayValues = [];

        if (in_array($field['field_type'], ['checkbox', 'multiselect'], true) && is_array($rawValue) && $field['options_text'] !== '') {
            $allowedValues = array_map(static fn(array $option): string => (string) ($option['value'] ?? ''), contactFormParseOptionsText((string) $field['options_text']));
            foreach ($rawValue as $checkedVal) {
                $checkedVal = trim((string) $checkedVal);
                if ($checkedVal !== '' && $allowedValues !== [] && !in_array($checkedVal, $allowedValues, true)) {
                    $fieldErrors[$field['name']] = 'Invalid selection for ' . $field['label'] . '.';
                    continue 2;
                }
            }
        }

        if (is_array($rawValue)) {
            $normalizedArrayValues = array_values(array_filter(
                array_map(static fn($value): string => trim((string) $value), $rawValue),
                static fn(string $value): bool => $value !== ''
            ));
            $rawValue = implode(', ', $normalizedArrayValues);
        }

        $maxLength = $field['field_type'] === 'textarea' ? 5000 : 2000;
        $value = contactFormLimit(trim((string) $rawValue), $maxLength);

        if ($field['field_type'] === 'file') {
            if (!empty($_FILES[$field['name']]['tmp_name']) && $_FILES[$field['name']]['error'] === UPLOAD_ERR_OK) {
                if (function_exists('cmsValidateMediaUploadFile') && function_exists('cmsUploadsPath') && function_exists('kernelEnsureDirectory') && function_exists('kernelCopyFile')) {
                    $validated = cmsValidateMediaUploadFile($_FILES[$field['name']]['tmp_name'], $_FILES[$field['name']]['name']);
                    if (empty($validated['error'])) {
                        $yearMonth = date('Y') . '/' . date('m');
                        $subPath = 'contact-forms/' . $yearMonth;
                        $destDir = cmsUploadsPath() . '/' . $subPath;
                        kernelEnsureDirectory($destDir);
                        $destFile = $validated['safe_filename'] ?? basename($_FILES[$field['name']]['name']);
                        if (kernelCopyFile($_FILES[$field['name']]['tmp_name'], $destDir . '/' . $destFile)) {
                            $value = $subPath . '/' . $destFile;
                        } else {
                            $fieldErrors[$field['name']] = 'Failed to save uploaded file.';
                            continue;
                        }
                    } else {
                        $fieldErrors[$field['name']] = $validated['error'];
                        continue;
                    }
                } else {
                    $fieldErrors[$field['name']] = 'File uploads are currently disabled (missing media helpers).';
                    continue;
                }
            } elseif ($field['required']) {
                $fieldErrors[$field['name']] = $field['label'] . ' is required.';
                continue;
            } else {
                $value = '';
            }
        }

        if ($field['field_type'] === 'file') {
            if (!empty($_FILES[$field['name']]['tmp_name']) && $_FILES[$field['name']]['error'] === UPLOAD_ERR_OK) {
                if (function_exists('cmsValidateMediaUploadFile') && function_exists('cmsUploadsPath') && function_exists('kernelEnsureDirectory') && function_exists('kernelCopyFile')) {
                    $validated = cmsValidateMediaUploadFile($_FILES[$field['name']]['tmp_name'], $_FILES[$field['name']]['name']);
                    if (empty($validated['error'])) {
                        $yearMonth = date('Y') . '/' . date('m');
                        $subPath = 'contact-forms/' . $yearMonth;
                        $destDir = cmsUploadsPath() . '/' . $subPath;
                        kernelEnsureDirectory($destDir);
                        $destFile = $validated['safe_filename'] ?? basename($_FILES[$field['name']]['name']);
                        if (kernelCopyFile($_FILES[$field['name']]['tmp_name'], $destDir . '/' . $destFile)) {
                            $value = $subPath . '/' . $destFile;
                        } else {
                            $fieldErrors[$field['name']] = 'Failed to save uploaded file.';
                            continue;
                        }
                    } else {
                        $fieldErrors[$field['name']] = $validated['error'];
                        continue;
                    }
                } else {
                    $fieldErrors[$field['name']] = 'File uploads are currently disabled (missing media helpers).';
                    continue;
                }
            } elseif ($field['required']) {
                $fieldErrors[$field['name']] = $field['label'] . ' is required.';
                continue;
            } else {
                $value = '';
            }
        }
        if ($field['required'] && $value === '') {
            $fieldErrors[$field['name']] = $field['label'] . ' is required.';
            continue;
        }

        if ($value !== '') {
            if ($field['field_type'] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $fieldErrors[$field['name']] = 'Please enter a valid email for ' . $field['label'] . '.';
                continue;
            }

            if ($field['field_type'] === 'tel' && !preg_match('/^[0-9\+\(\)\-\.\s\/]{5,40}$/', $value)) {
                $fieldErrors[$field['name']] = 'Please enter a valid phone number for ' . $field['label'] . '.';
                continue;
            }

            if ($field['field_type'] === 'number' && !is_numeric($value)) {
                $fieldErrors[$field['name']] = $field['label'] . ' must be a number.';
                continue;
            }

            if ($field['field_type'] === 'select') {
                $allowedValues = array_map(static fn(array $option): string => (string) ($option['value'] ?? ''), contactFormParseOptionsText((string) ($field['options_text'] ?? '')));
                if ($allowedValues === [] || !in_array($value, $allowedValues, true)) {
                    $fieldErrors[$field['name']] = 'Please choose a valid option for ' . $field['label'] . '.';
                    continue;
                }
            }

            if ($field['field_type'] === 'radio') {
                $allowedValues = array_map(static fn(array $option): string => (string) ($option['value'] ?? ''), contactFormParseOptionsText((string) ($field['options_text'] ?? '')));
                if ($allowedValues === [] || !in_array($value, $allowedValues, true)) {
                    $fieldErrors[$field['name']] = 'Please choose a valid option for ' . $field['label'] . '.';
                    continue;
                }
            }

            if ($field['field_type'] === 'checkbox' && $normalizedArrayValues !== [] && $field['options_text'] !== '') {
                $allowedValues = array_map(static fn(array $option): string => (string) ($option['value'] ?? ''), contactFormParseOptionsText((string) ($field['options_text'] ?? '')));
                foreach ($normalizedArrayValues as $checkedValue) {
                    if ($allowedValues !== [] && !in_array($checkedValue, $allowedValues, true)) {
                        $fieldErrors[$field['name']] = 'Invalid selection for ' . $field['label'] . '.';
                        continue 2;
                    }
                }
            }
        }

        if ($value !== '') {
            if ($field['field_type'] !== 'hidden') {
                $hasValue = true;
            }
            if ($summaryName === '' && $field['field_type'] !== 'hidden') {
                $summaryName = $value;
            }
            if ($summaryEmail === '' && $field['field_type'] !== 'hidden' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $summaryEmail = $value;
            }
        }

        $records[] = [
            'label' => $field['label'],
            'name' => $field['name'],
            'type' => $field['field_type'],
            'value' => $value,
            'required' => $field['required'],
        ];
    }

    if ($fieldErrors !== []) {
        return contactFormSubmissionError('Please correct the highlighted fields and try again.', $fieldErrors);
    }

    if (!$hasValue) {
        return contactFormSubmissionError('Please fill out at least one field.');
    }

    return [
        'records' => $records,
        'name' => $summaryName !== '' ? $summaryName : 'Saved Form Submission',
        'email' => $summaryEmail,
        'message' => contactFormBuildSubmissionSummary($records),
    ];
}

function contactFormAdminForms(array $params = []): void
{
    $user = contactFormRequireAdmin();
    $message = contactFormPullFlash();
    $forms = [];
    $stats = ['total' => 0, 'active' => 0, 'inactive' => 0];
    $schema = contactFormSchemaStatus();

    if (empty($schema['ready'])) {
        $message = ['type' => 'error', 'text' => (string) ($schema['message'] ?? 'Contact form schema is not ready yet.')];
    } else {
        try {
            $rows = contactFormDb()->query(
                'SELECT f.id, f.name, f.slug, f.success_message, f.submit_label, f.captcha_enabled, f.status, f.created_at, f.updated_at,'
                . ' COUNT(DISTINCT ff.id) AS field_count, COUNT(DISTINCT s.id) AS submission_count'
                . ' FROM contact_forms f'
                . ' LEFT JOIN contact_form_fields ff ON ff.form_id = f.id'
                . ' LEFT JOIN contact_form_submissions s ON s.form_id = f.id'
                . ' GROUP BY f.id, f.name, f.slug, f.success_message, f.submit_label, f.captcha_enabled, f.status, f.created_at, f.updated_at'
                . ' ORDER BY f.created_at DESC'
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $form = contactFormNormalizeFormRow($row);
                $form['field_count'] = (int) ($row['field_count'] ?? 0);
                $form['submission_count'] = (int) ($row['submission_count'] ?? 0);
                $forms[] = $form;
            }
        } catch (Throwable $e) {
            write_log('contact-form: failed to load forms list: ' . $e->getMessage(), 'error');
        }
    }

    $stats['total'] = count($forms);
    foreach ($forms as $form) {
        if (($form['status'] ?? '') === 'active') {
            $stats['active']++;
        } else {
            $stats['inactive']++;
        }
    }

    echo contactFormRenderTemplate('admin/forms.disyl', contactFormAdminPageContext(
        $user,
        'contact_forms',
        'Contact Forms',
        [
            ['label' => 'Contact Forms', 'url' => ''],
        ],
        [
            'forms' => $forms,
            'message' => $message,
            'stats' => $stats,
        ]
    ));

    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

function contactFormAdminFormCreate(array $params = []): void
{
    $user = contactFormRequireAdmin();
    $message = contactFormPullFlash();
    $error = null;
    $form = contactFormFormDefaults();
    $schema = contactFormSchemaStatus();

    if (empty($schema['ready'])) {
        $error = (string) ($schema['message'] ?? 'Contact form schema is not ready yet.');
    } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        app()->csrfEnforce();
        $input = contactFormInput();
        $validation = contactFormValidateFormInput($input);

        if (!empty($validation['error'])) {
            $error = (string) $validation['error'];
            $form = contactFormHydrateFormFromInput($input, $form);
        } else {
            try {
                $payload = $validation['data'];
                $stmt = contactFormDb()->prepare(
                    'INSERT INTO contact_forms (name, slug, success_message, submit_label, confirmation_rules, notification_rules, captcha_enabled, status, created_at, updated_at)'
                    . ' VALUES (:name, :slug, :success_message, :submit_label, :confirmation_rules, :notification_rules, :captcha_enabled, :status, NOW(), NOW())'
                );
                $stmt->execute([
                    ':name' => $payload['name'],
                    ':slug' => $payload['slug'],
                    ':success_message' => $payload['success_message'],
                    ':submit_label' => $payload['submit_label'],
                    ':confirmation_rules' => contactFormRulesToJson(is_array($payload['confirmation_rules'] ?? null) ? $payload['confirmation_rules'] : []),
                    ':notification_rules' => contactFormRulesToJson(is_array($payload['notification_rules'] ?? null) ? $payload['notification_rules'] : []),
                    ':captcha_enabled' => $payload['captcha_enabled'],
                    ':status' => $payload['status'],
                ]);
                $formId = (int) contactFormDb()->lastInsertId();

                $fieldStmt = contactFormDb()->prepare(
                    'INSERT INTO contact_form_fields (form_id, field_type, label, name, placeholder, help_text, options_text, required, sort_order, created_at, updated_at)'
                    . ' VALUES (:form_id, :field_type, :label, :name, :placeholder, :help_text, :options_text, :required, :sort_order, NOW(), NOW())'
                );
                foreach ([
                    ['field_type' => 'text', 'label' => 'Full Name', 'name' => 'full_name', 'placeholder' => 'Your full name', 'required' => 1, 'sort_order' => 10],
                    ['field_type' => 'email', 'label' => 'Email Address', 'name' => 'email_address', 'placeholder' => 'name@example.com', 'required' => 1, 'sort_order' => 20],
                    ['field_type' => 'textarea', 'label' => 'Message', 'name' => 'message', 'placeholder' => 'Your message…', 'required' => 1, 'sort_order' => 30],
                ] as $df) {
                    $fieldStmt->execute([
                        ':form_id' => $formId,
                        ':field_type' => $df['field_type'],
                        ':label' => $df['label'],
                        ':name' => $df['name'],
                        ':placeholder' => $df['placeholder'],
                        ':help_text' => '',
                        ':options_text' => '',
                        ':required' => $df['required'],
                        ':sort_order' => $df['sort_order'],
                    ]);
                }

                contactFormSetFlash('success', 'Form created with default fields. Customise them in the Fields tab.');
                contactFormRedirectTo('/cms/admin/contact-forms/' . $formId . '/edit');
            } catch (Throwable $e) {
                write_log('contact-form: failed to create form: ' . $e->getMessage(), 'error');
                $error = 'Failed to create the form.';
                $form = contactFormHydrateFormFromInput($input, $form);
            }
        }
    }

    echo contactFormRenderTemplate('admin/form-edit.disyl', contactFormAdminPageContext(
        $user,
        'contact_forms',
        'Create Contact Form',
        [
            ['label' => 'Contact Forms', 'url' => contactFormPath('/cms/admin/contact-forms')],
            ['label' => 'Create', 'url' => ''],
        ],
        [
            'form' => $form,
            'fields' => [],
            'condition_fields_json' => contactFormConditionFieldsJson([]),
            'message' => $message,
            'error' => $error,
            'is_edit' => false,
            'shortcode' => '',
            'field_type_options' => contactFormFieldTypeOptions(),
            'active_tab' => 'overview',
        ]
    ));

    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

function contactFormAdminFormEdit(array $params = []): void
{
    $user = contactFormRequireAdmin();
    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        contactFormSetFlash('error', (string) ($schema['message'] ?? 'Contact form schema is not ready yet.'));
        contactFormRedirectTo('/cms/admin/contact-forms');
    }

    $formId = (int) ($params['id'] ?? 0);
    $form = contactFormGetFormById($formId, true, false);
    if (!$form) {
        contactFormRenderNotFound('Contact Form Not Found');
        return;
    }

    $message = contactFormPullFlash();
    $error = null;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        app()->csrfEnforce();
        $input = contactFormInput();
        $validation = contactFormValidateFormInput($input, $formId);

        if (!empty($validation['error'])) {
            $error = (string) $validation['error'];
            $form = contactFormHydrateFormFromInput($input, $form);
            $form['id'] = $formId;
            $form['fields'] = contactFormGetFieldsForForm($formId);
        } else {
            try {
                $payload = $validation['data'];
                $stmt = contactFormDb()->prepare(
                    'UPDATE contact_forms'
                    . ' SET name = :name, slug = :slug, success_message = :success_message, submit_label = :submit_label,'
                    . ' confirmation_rules = :confirmation_rules, notification_rules = :notification_rules,'
                    . ' captcha_enabled = :captcha_enabled, status = :status, updated_at = NOW()'
                    . ' WHERE id = :id LIMIT 1'
                );
                $stmt->execute([
                    ':name' => $payload['name'],
                    ':slug' => $payload['slug'],
                    ':success_message' => $payload['success_message'],
                    ':submit_label' => $payload['submit_label'],
                    ':confirmation_rules' => contactFormRulesToJson(is_array($payload['confirmation_rules'] ?? null) ? $payload['confirmation_rules'] : []),
                    ':notification_rules' => contactFormRulesToJson(is_array($payload['notification_rules'] ?? null) ? $payload['notification_rules'] : []),
                    ':captcha_enabled' => $payload['captcha_enabled'],
                    ':status' => $payload['status'],
                    ':id' => $formId,
                ]);

                contactFormSetFlash('success', 'Form saved.');
                contactFormRedirectTo('/cms/admin/contact-forms/' . $formId . '/edit');
            } catch (Throwable $e) {
                write_log('contact-form: failed to update form ' . $formId . ': ' . $e->getMessage(), 'error');
                $error = 'Failed to save the form.';
                $form = contactFormHydrateFormFromInput($input, $form);
                $form['id'] = $formId;
                $form['fields'] = contactFormGetFieldsForForm($formId);
            }
        }
    }

    $fields = is_array($form['fields'] ?? null) ? $form['fields'] : contactFormGetFieldsForForm($formId);
    $shortcode = $form['slug'] !== '' ? '[contact-form form="' . $form['slug'] . '"]' : '';

    echo contactFormRenderTemplate('admin/form-edit.disyl', contactFormAdminPageContext(
        $user,
        'contact_forms',
        'Edit Contact Form',
        [
            ['label' => 'Contact Forms', 'url' => contactFormPath('/cms/admin/contact-forms')],
            ['label' => $form['name'] !== '' ? $form['name'] : 'Edit', 'url' => ''],
        ],
        [
            'form' => $form,
            'fields' => $fields,
            'condition_fields_json' => contactFormConditionFieldsJson($fields),
            'message' => $message,
            'error' => $error,
            'is_edit' => true,
            'shortcode' => $shortcode,
            'field_type_options' => contactFormFieldTypeOptions(),
            'active_tab' => 'overview',
        ]
    ));

    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

function contactFormAdminFormDelete(array $params = []): void
{
    contactFormRequireAdmin();
    app()->csrfEnforce();

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        contactFormSetFlash('error', (string) ($schema['message'] ?? 'Contact form schema is not ready yet.'));
        contactFormRedirectTo('/cms/admin/contact-forms');
    }

    $formId = (int) ($params['id'] ?? 0);
    $form = contactFormGetFormById($formId, false, false);
    if (!$form) {
        contactFormSetFlash('error', 'Form not found.');
        contactFormRedirectTo('/cms/admin/contact-forms');
    }

    try {
        $db = contactFormDb();
        $stmt = $db->prepare('DELETE FROM contact_form_fields WHERE form_id = :form_id');
        $stmt->execute([':form_id' => $formId]);

        $stmt = $db->prepare('DELETE FROM contact_forms WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $formId]);

        contactFormSetFlash('success', 'Form deleted. Existing entries were kept in the log.');
    } catch (Throwable $e) {
        write_log('contact-form: failed to delete form ' . $formId . ': ' . $e->getMessage(), 'error');
        contactFormSetFlash('error', 'Failed to delete the form.');
    }

    contactFormRedirectTo('/cms/admin/contact-forms');
}

function contactFormAdminFormPreview(array $params = []): void
{
    contactFormRequireAdmin();

    $formId = (int) ($params['id'] ?? 0);
    $schema = contactFormSchemaStatus();

    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    if (empty($schema['ready'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#94a3b8;padding:2rem">Schema not ready.</body></html>';
        return;
    }

    $form = contactFormGetFormById($formId, true, false);
    if (!$form) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;color:#94a3b8;padding:2rem">Form not found.</body></html>';
        return;
    }

    $settings = contactFormGetSettings();
    $submitLabel = trim((string) ($form['submit_label'] ?? ''));
    if ($submitLabel === '') {
        $submitLabel = 'Send Message';
    }

    $successMessage = trim((string) ($form['success_message'] ?? ''));
    if ($successMessage === '') {
        $successMessage = trim((string) ($settings['success_message'] ?? 'Thank you for your submission.'));
    }

    $fields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
    $renderId = 'cf-preview-' . $formId;

    if ($fields === []) {
        $formHtml = '<p style="font-family:sans-serif;color:#94a3b8;padding:2rem;text-align:center">No fields have been added to this form yet.</p>';
    } else {
        $fieldMarkup = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldMarkup[] = contactFormRenderFieldMarkup($field, $renderId);
        }

        $hiddenHtml = '<input type="hidden" name="form_id" value="' . $formId . '">';
        $captchaHtml = $form['captcha_enabled'] ? contactFormRenderCaptchaMarkup($renderId) : '';
        $honeypot = ($settings['spam_protection'] ?? 'honeypot') === 'honeypot';
        $formHtml = contactFormRenderFrame(
            $renderId,
            '',
            implode("\n", $fieldMarkup),
            $submitLabel,
            $successMessage,
            $hiddenHtml,
            '',
            $captchaHtml,
            $honeypot,
            $form['captcha_enabled'] === 1
        );
    }

    $formName = $esc(trim((string) ($form['name'] ?? 'Form')));
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preview: {$formName}</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; padding: 1.5rem 1.5rem 3rem; background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.cf-preview-banner {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
    color: #64748b; background: #e2e8f0; border-radius: 20px;
    padding: 4px 12px; margin-bottom: 1.25rem;
}
</style>
</head>
<body>
<div class="cf-preview-banner">
  <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
  Preview - submissions are disabled
</div>
{$formHtml}
</body>
</html>
HTML;
}

function contactFormAdminFieldCreate(array $params = []): void
{
    contactFormRequireAdmin();
    app()->csrfEnforce();

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        contactFormSetFlash('error', (string) ($schema['message'] ?? 'Contact form schema is not ready yet.'));
        contactFormRedirectTo('/cms/admin/contact-forms');
    }

    $formId = (int) ($params['id'] ?? 0);
    $form = contactFormGetFormById($formId, false, false);
    if (!$form) {
        contactFormSetFlash('error', 'Form not found.');
        contactFormRedirectTo('/cms/admin/contact-forms');
    }

    $input = contactFormInput();
    $validation = contactFormValidateFieldInput($input, $formId);
    if (!empty($validation['error'])) {
        contactFormSetFlash('error', (string) $validation['error']);
        header('Location: ' . contactFormPath('/cms/admin/contact-forms/' . $formId . '/edit') . '#fields');
        exit;
    }

    try {
        $payload = $validation['data'];
        $conditionalLogicJson = contactFormConditionalLogicToJson($payload['conditional_logic'] ?? []);
        $stmt = contactFormDb()->prepare(
            'INSERT INTO contact_form_fields (form_id, field_type, label, name, placeholder, help_text, options_text, conditional_logic, required, sort_order, created_at, updated_at)'
            . ' VALUES (:form_id, :field_type, :label, :name, :placeholder, :help_text, :options_text, :conditional_logic, :required, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            ':form_id' => $formId,
            ':field_type' => $payload['field_type'],
            ':label' => $payload['label'],
            ':name' => $payload['name'],
            ':placeholder' => $payload['placeholder'],
            ':help_text' => $payload['help_text'],
            ':options_text' => $payload['options_text'],
            ':conditional_logic' => $conditionalLogicJson,
            ':required' => $payload['required'],
            ':sort_order' => $payload['sort_order'],
        ]);

        contactFormSetFlash('success', 'Field added.');
    } catch (Throwable $e) {
        write_log('contact-form: failed to create field for form ' . $formId . ': ' . $e->getMessage(), 'error');
        contactFormSetFlash('error', 'Failed to add the field.');
    }

    header('Location: ' . contactFormPath('/cms/admin/contact-forms/' . $formId . '/edit') . '#fields');
    exit;
}

function contactFormAdminFieldReorder(array $params = []): void
{
    contactFormRequireAdmin();
    app()->csrfEnforce();

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        contactFormSetFlash('error', (string) ($schema['message'] ?? 'Contact form schema is not ready yet.'));
        contactFormRedirectTo('/cms/admin/contact-forms');
    }

    $formId = (int) ($params['id'] ?? 0);
    $form = contactFormGetFormById($formId, false, false);
    if (!$form) {
        contactFormSetFlash('error', 'Form not found.');
        contactFormRedirectTo('/cms/admin/contact-forms');
    }

    $fieldOrder = trim((string) (contactFormInput()['field_order'] ?? ''));
    $orderedIds = array_values(array_filter(array_map('intval', explode(',', $fieldOrder)), static fn(int $value): bool => $value > 0));
    if ($orderedIds === []) {
        contactFormSetFlash('error', 'No field order was provided.');
        header('Location: ' . contactFormPath('/cms/admin/contact-forms/' . $formId . '/edit') . '#fields');
        exit;
    }

    try {
        $existingStmt = contactFormDb()->prepare('SELECT id FROM contact_form_fields WHERE form_id = :form_id ORDER BY sort_order ASC, id ASC');
        $existingStmt->execute([':form_id' => $formId]);
        $existingIds = array_map('intval', $existingStmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        sort($existingIds);
        $checkIds = $orderedIds;
        sort($checkIds);

        if ($existingIds !== $checkIds) {
            throw new RuntimeException('Field order payload did not match the current field set.');
        }

        $db = contactFormDb();
        $db->beginTransaction();
        $stmt = $db->prepare('UPDATE contact_form_fields SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND form_id = :form_id LIMIT 1');
        foreach ($orderedIds as $index => $fieldId) {
            $stmt->execute([
                ':sort_order' => ($index + 1) * 10,
                ':id' => $fieldId,
                ':form_id' => $formId,
            ]);
        }
        $db->commit();
        contactFormSetFlash('success', 'Field order updated.');
    } catch (Throwable $e) {
        try {
            $db = contactFormDb();
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        } catch (Throwable $ignored) {
        }

        write_log('contact-form: failed to reorder fields for form ' . $formId . ': ' . $e->getMessage(), 'error');
        contactFormSetFlash('error', 'Failed to save the new field order.');
    }

    header('Location: ' . contactFormPath('/cms/admin/contact-forms/' . $formId . '/edit') . '#fields');
    exit;
}

function contactFormAdminFieldUpdate(array $params = []): void
{
    contactFormRequireAdmin();
    app()->csrfEnforce();

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        contactFormSetFlash('error', (string) ($schema['message'] ?? 'Contact form schema is not ready yet.'));
        contactFormRedirectTo('/cms/admin/contact-forms');
    }

    $formId = (int) ($params['id'] ?? 0);
    $fieldId = (int) ($params['fieldId'] ?? 0);
    $form = contactFormGetFormById($formId, false, false);
    $field = contactFormGetFieldById($fieldId);
    if (!$form || !$field || (int) ($field['form_id'] ?? 0) !== $formId) {
        contactFormSetFlash('error', 'Field not found.');
        header('Location: ' . contactFormPath('/cms/admin/contact-forms/' . $formId . '/edit') . '#fields');
        exit;
    }

    $input = contactFormInput();
    $validation = contactFormValidateFieldInput($input, $formId, $fieldId);
    if (!empty($validation['error'])) {
        contactFormSetFlash('error', (string) $validation['error']);
        header('Location: ' . contactFormPath('/cms/admin/contact-forms/' . $formId . '/edit') . '#fields');
        exit;
    }

    try {
        $payload = $validation['data'];
        $conditionalLogicJson = contactFormConditionalLogicToJson($payload['conditional_logic'] ?? []);
        $stmt = contactFormDb()->prepare(
            'UPDATE contact_form_fields'
            . ' SET field_type = :field_type, label = :label, name = :name, placeholder = :placeholder, help_text = :help_text, options_text = :options_text, conditional_logic = :conditional_logic, required = :required,'
            . ' sort_order = :sort_order, updated_at = NOW()'
            . ' WHERE id = :id AND form_id = :form_id LIMIT 1'
        );
        $stmt->execute([
            ':field_type' => $payload['field_type'],
            ':label' => $payload['label'],
            ':name' => $payload['name'],
            ':placeholder' => $payload['placeholder'],
            ':help_text' => $payload['help_text'],
            ':options_text' => $payload['options_text'],
            ':conditional_logic' => $conditionalLogicJson,
            ':required' => $payload['required'],
            ':sort_order' => $payload['sort_order'],
            ':id' => $fieldId,
            ':form_id' => $formId,
        ]);

        contactFormSetFlash('success', 'Field updated.');
    } catch (Throwable $e) {
        write_log('contact-form: failed to update field ' . $fieldId . ': ' . $e->getMessage(), 'error');
        contactFormSetFlash('error', 'Failed to update the field.');
    }

    header('Location: ' . contactFormPath('/cms/admin/contact-forms/' . $formId . '/edit') . '#fields');
    exit;
}

function contactFormAdminFieldDelete(array $params = []): void
{
    contactFormRequireAdmin();
    app()->csrfEnforce();

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        contactFormSetFlash('error', (string) ($schema['message'] ?? 'Contact form schema is not ready yet.'));
        contactFormRedirectTo('/cms/admin/contact-forms');
    }

    $formId = (int) ($params['id'] ?? 0);
    $fieldId = (int) ($params['fieldId'] ?? 0);
    $field = contactFormGetFieldById($fieldId);
    if (!$field || (int) ($field['form_id'] ?? 0) !== $formId) {
        contactFormSetFlash('error', 'Field not found.');
        header('Location: ' . contactFormPath('/cms/admin/contact-forms/' . $formId . '/edit') . '#fields');
        exit;
    }

    try {
        $stmt = contactFormDb()->prepare('DELETE FROM contact_form_fields WHERE id = :id AND form_id = :form_id LIMIT 1');
        $stmt->execute([
            ':id' => $fieldId,
            ':form_id' => $formId,
        ]);

        contactFormSetFlash('success', 'Field deleted.');
    } catch (Throwable $e) {
        write_log('contact-form: failed to delete field ' . $fieldId . ': ' . $e->getMessage(), 'error');
        contactFormSetFlash('error', 'Failed to delete the field.');
    }

    header('Location: ' . contactFormPath('/cms/admin/contact-forms/' . $formId . '/edit') . '#fields');
    exit;
}

function contactFormAdminSubmissions(array $params = []): void
{
    $user = contactFormRequireAdmin();
    $message = contactFormPullFlash();
    $input = contactFormInput();
    $filters = contactFormBuildSubmissionFilters($input);
    $selectedFormId = (int) $filters['selected_form_id'];
    $selectedStatus = (string) $filters['selected_status'];
    $page = max(1, (int) ($input['page'] ?? 1));
    $limit = 25;
    $offset = ($page - 1) * $limit;
    $forms = contactFormListForms(false);
    $submissions = [];
    $total = 0;
    $schema = contactFormSchemaStatus();

    if (empty($schema['ready'])) {
        $message = ['type' => 'error', 'text' => (string) ($schema['message'] ?? 'Contact form schema is not ready yet.')];
    } else {
        try {
            $whereSql = (string) $filters['where_sql'];
            $bind = is_array($filters['bind']) ? $filters['bind'] : [];

            $countStmt = contactFormDb()->prepare(
                'SELECT COUNT(*) FROM contact_form_submissions s' . $whereSql
            );
            $countStmt->execute($bind);
            $total = (int) $countStmt->fetchColumn();

            $listStmt = contactFormDb()->prepare(
                'SELECT s.id, s.form_id, s.name, s.email, s.message, s.form_data, s.status, s.created_at, f.name AS form_name'
                . ' FROM contact_form_submissions s'
                . ' LEFT JOIN contact_forms f ON f.id = s.form_id'
                . $whereSql
                . ' ORDER BY s.created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
            );
            $listStmt->execute($bind);
            $rows = $listStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $status = contactFormCanonicalSubmissionStatus((string) ($row['status'] ?? 'new'));

                $submissions[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'form_label' => contactFormSubmissionFormLabel($row),
                    'name' => trim((string) ($row['name'] ?? '')),
                    'email' => trim((string) ($row['email'] ?? '')),
                    'status' => $status,
                    'status_label' => contactFormSubmissionStatusLabel($status),
                    'preview' => contactFormSubmissionPreview((string) ($row['form_data'] ?? ''), (string) ($row['message'] ?? '')),
                    'created_at' => trim((string) ($row['created_at'] ?? '')),
                ];
            }
        } catch (Throwable $e) {
            write_log('contact-form: failed to load submissions: ' . $e->getMessage(), 'error');
        }
    }

    $totalPages = max(1, (int) ceil($total / $limit));
    $filterQuery = http_build_query(array_filter([
        'form_id' => $selectedFormId > 0 ? $selectedFormId : null,
        'status' => $selectedStatus !== '' ? $selectedStatus : null,
    ], static fn($value): bool => $value !== null && $value !== '' && $value !== 0));
    $exportUrl = contactFormPath('/cms/admin/contact-forms/submissions/export' . ($filterQuery !== '' ? '?' . $filterQuery : ''));

    echo contactFormRenderTemplate('admin/submissions.disyl', contactFormAdminPageContext(
        $user,
        'contact_form_submissions',
        'Contact Form Entries',
        [
            ['label' => 'Contact Forms', 'url' => contactFormPath('/cms/admin/contact-forms')],
            ['label' => 'Entries', 'url' => ''],
        ],
        [
            'submissions' => $submissions,
            'forms' => $forms,
            'message' => $message,
            'selected_form_id' => $selectedFormId,
            'selected_status' => $selectedStatus,
            'status_options' => contactFormSubmissionStatusOptions(),
            'export_url' => $exportUrl,
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'prev_page' => max(1, $page - 1),
            'next_page' => min($totalPages, $page + 1),
        ]
    ));

    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

function contactFormAdminSubmissionDetail(array $params = []): void
{
    $user = contactFormRequireAdmin();
    $message = contactFormPullFlash();
    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        contactFormSetFlash('error', (string) ($schema['message'] ?? 'Contact form schema is not ready yet.'));
        contactFormRedirectTo('/cms/admin/contact-forms/submissions');
    }

    $submissionId = (int) ($params['id'] ?? 0);
    $submission = contactFormGetSubmissionById($submissionId);
    if (!$submission) {
        contactFormRenderNotFound('Submission Not Found');
        return;
    }

    echo contactFormRenderTemplate('admin/submission-detail.disyl', contactFormAdminPageContext(
        $user,
        'contact_form_submissions',
        'Submission Detail',
        [
            ['label' => 'Contact Forms', 'url' => contactFormPath('/cms/admin/contact-forms')],
            ['label' => 'Entries', 'url' => contactFormPath('/cms/admin/contact-forms/submissions')],
            ['label' => 'Submission #' . (int) $submission['id'], 'url' => ''],
        ],
        [
            'submission' => $submission,
            'records' => is_array($submission['records'] ?? null) ? $submission['records'] : [],
            'message' => $message,
            'status_options' => contactFormSubmissionStatusOptions(),
        ]
    ));

    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

function contactFormAdminSubmissionStatusUpdate(array $params = []): void
{
    $user = contactFormRequireAdmin();
    app()->csrfEnforce();

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        contactFormSetFlash('error', (string) ($schema['message'] ?? 'Contact form schema is not ready yet.'));
        contactFormRedirectTo('/cms/admin/contact-forms/submissions');
    }

    $submissionId = (int) ($params['id'] ?? 0);
    $submission = contactFormGetSubmissionById($submissionId);
    if (!$submission) {
        contactFormSetFlash('error', 'Submission not found.');
        contactFormRedirectTo('/cms/admin/contact-forms/submissions');
    }

    $status = contactFormNormalizeSubmissionStatus((string) (contactFormInput()['status'] ?? ''));
    if ($status === '') {
        contactFormSetFlash('error', 'Choose a valid submission status.');
        contactFormRedirectTo('/cms/admin/contact-forms/submissions/' . $submissionId);
    }

    $reviewedAt = null;
    $reviewedBy = null;
    if ($status !== 'new') {
        $reviewedAt = trim((string) ($submission['reviewed_at'] ?? ''));
        if ($reviewedAt === '') {
            $reviewedAt = date('Y-m-d H:i:s');
        }

        $reviewedBy = (int) ($user['id'] ?? 0);
        if ($reviewedBy <= 0) {
            $reviewedBy = null;
        }
    }

    try {
        $stmt = contactFormDb()->prepare(
            'UPDATE contact_form_submissions'
            . ' SET status = :status, reviewed_at = :reviewed_at, reviewed_by = :reviewed_by, updated_at = NOW()'
            . ' WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':status', $status, \PDO::PARAM_STR);
        $stmt->bindValue(':reviewed_at', $reviewedAt, $reviewedAt === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':reviewed_by', $reviewedBy, $reviewedBy === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(':id', $submissionId, \PDO::PARAM_INT);
        $stmt->execute();

        contactFormSetFlash('success', 'Submission status updated.');
    } catch (Throwable $e) {
        write_log('contact-form: failed to update submission status ' . $submissionId . ': ' . $e->getMessage(), 'error');
        contactFormSetFlash('error', 'Failed to update submission status.');
    }

    contactFormRedirectTo('/cms/admin/contact-forms/submissions/' . $submissionId);
}

function contactFormAdminSubmissionsExport(array $params = []): void
{
    contactFormRequireAdmin();

    $schema = contactFormSchemaStatus();
    if (empty($schema['ready'])) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo (string) ($schema['message'] ?? 'Contact form schema is not ready yet.');
        exit;
    }

    $filters = contactFormBuildSubmissionFilters(contactFormInput());

    try {
        $stmt = contactFormDb()->prepare(
            'SELECT s.id, s.form_id, s.name, s.email, s.message, s.form_data, s.ip_address, s.status, s.created_at, s.reviewed_at, s.reviewed_by,'
            . ' f.name AS form_name, f.slug AS form_slug'
            . ' FROM contact_form_submissions s'
            . ' LEFT JOIN contact_forms f ON f.id = s.form_id'
            . (string) $filters['where_sql']
            . ' ORDER BY s.created_at DESC'
        );
        $stmt->execute(is_array($filters['bind']) ? $filters['bind'] : []);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        write_log('contact-form: failed to export submissions: ' . $e->getMessage(), 'error');
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Failed to export submissions.';
        exit;
    }

    $dynamicColumns = [];
    foreach ($rows as $row) {
        foreach (contactFormSubmissionRecordsFromRow(is_array($row) ? $row : []) as $record) {
            if (!is_array($record)) {
                continue;
            }

            $fieldName = trim((string) ($record['name'] ?? ''));
            if ($fieldName === '') {
                $fieldName = contactFormFieldNameify((string) ($record['label'] ?? 'field'));
            }
            if ($fieldName === '') {
                continue;
            }

            $dynamicColumns[$fieldName] = 'field_' . $fieldName;
        }
    }

    $headers = array_merge([
        'submission_id',
        'status',
        'form_label',
        'form_slug',
        'submitter_name',
        'submitter_email',
        'summary',
        'ip_address',
        'submitted_at',
        'reviewed_at',
    ], array_values($dynamicColumns));

    $filename = 'contact-form-submissions-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        echo 'Failed to open export stream.';
        exit;
    }

    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers);

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $recordValues = array_fill_keys(array_keys($dynamicColumns), '');
        foreach (contactFormSubmissionRecordsFromRow($row) as $record) {
            if (!is_array($record)) {
                continue;
            }

            $fieldName = trim((string) ($record['name'] ?? ''));
            if ($fieldName === '') {
                $fieldName = contactFormFieldNameify((string) ($record['label'] ?? 'field'));
            }
            if ($fieldName === '' || !array_key_exists($fieldName, $recordValues)) {
                continue;
            }

            $recordValues[$fieldName] = trim((string) ($record['value'] ?? ''));
        }

        fputcsv($output, array_merge([
            (int) ($row['id'] ?? 0),
            contactFormSubmissionStatusLabel(contactFormCanonicalSubmissionStatus((string) ($row['status'] ?? 'new'))),
            contactFormSubmissionFormLabel($row),
            trim((string) ($row['form_slug'] ?? '')),
            trim((string) ($row['name'] ?? '')),
            trim((string) ($row['email'] ?? '')),
            contactFormSubmissionPreview((string) ($row['form_data'] ?? ''), (string) ($row['message'] ?? '')),
            trim((string) ($row['ip_address'] ?? '')),
            trim((string) ($row['created_at'] ?? '')),
            trim((string) ($row['reviewed_at'] ?? '')),
        ], array_values($recordValues)));
    }

    fclose($output);
    exit;
}

function apiGetContactFormCaptcha(array $params = []): void
{
    $captcha = contactFormGenerateCaptcha();
    contactFormJsonOk([
        'question' => $captcha['question'],
        'token' => $captcha['token'],
    ]);
}

function submitContactForm(array $params = []): void
{
    $input = contactFormInput();
    if (!empty($input['_json_error'])) {
        contactFormJsonError('Invalid JSON payload.', 400);
    }

    $settings = contactFormGetSettings();
    $savedForm = null;
    $formId = (int) ($input['form_id'] ?? 0);
    if ($formId > 0) {
        $schema = contactFormSchemaStatus();
        if (empty($schema['ready'])) {
            contactFormJsonError((string) ($schema['message'] ?? 'Contact form schema is not ready yet.'), 503, ['retry' => true]);
        }

        $savedForm = contactFormGetFormById($formId, true, true);
        if (!$savedForm) {
            contactFormJsonError('Selected form is unavailable.', 404);
        }
    }

    $responseMessage = trim((string) ($savedForm['success_message'] ?? ''));
    if ($responseMessage === '') {
        $responseMessage = trim((string) ($settings['success_message'] ?? 'Thank you for your message.'));
    }

    $honeypotTriggered = function_exists('antispamHoneypotTriggered')
        ? antispamHoneypotTriggered($input, '_hp_name')
        : !empty($input['_hp_name']);

    if (($settings['spam_protection'] ?? 'honeypot') === 'honeypot' && $honeypotTriggered) {
        contactFormJsonOk(['message' => $responseMessage]);
    }

    if ($savedForm && (int) ($savedForm['captcha_enabled'] ?? 0) === 1) {
        $captchaToken = trim((string) ($input['captcha_token'] ?? ''));
        $captchaAnswer = trim((string) ($input['captcha_answer'] ?? ''));
        if (!contactFormVerifyCaptcha($captchaToken, $captchaAnswer)) {
            contactFormJsonError('Incorrect answer. Please try again.', 422, [
                'refresh_captcha' => true,
                'field_errors' => ['captcha_answer' => 'Incorrect answer. Please try again.'],
            ]);
        }
    }

    $fields = $savedForm && is_array($savedForm['fields'] ?? null)
        ? $savedForm['fields']
        : [];

    $prepared = $savedForm
        ? contactFormPrepareDynamicSubmission($fields, $input)
        : contactFormPrepareLegacySubmission($input);

    if (!empty($prepared['error'])) {
        $extra = ($savedForm && (int) ($savedForm['captcha_enabled'] ?? 0) === 1)
            ? ['refresh_captcha' => true]
            : [];
        if (!empty($prepared['field_errors']) && is_array($prepared['field_errors'])) {
            $extra['field_errors'] = $prepared['field_errors'];
        }
        contactFormJsonError((string) $prepared['error'], 422, $extra);
    }

    $records = is_array($prepared['records'] ?? null) ? $prepared['records'] : [];
    $summaryName = contactFormLimit(trim((string) ($prepared['name'] ?? '')), 255);
    $summaryEmail = contactFormLimit(trim((string) ($prepared['email'] ?? '')), 255);
    $summaryMessage = contactFormLimit(trim((string) ($prepared['message'] ?? '')), 5000);

    if (($settings['store_submissions'] ?? '1') !== '0') {
        try {
            $stmt = contactFormDb()->prepare(
                'INSERT INTO contact_form_submissions (form_id, name, email, message, form_data, ip_address, created_at)'
                . ' VALUES (:form_id, :name, :email, :message, :form_data, :ip, NOW())'
            );
            $stmt->execute([
                ':form_id' => $savedForm ? (int) $savedForm['id'] : null,
                ':name' => $summaryName,
                ':email' => $summaryEmail,
                ':message' => $summaryMessage,
                ':form_data' => json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        } catch (Throwable $e) {
            write_log('contact-form: DB store failed: ' . $e->getMessage(), 'error');
        }
    }

    $fallbackRecipient = trim((string) ($settings['recipient_email'] ?? ''));
    $notificationRecipients = $savedForm
        ? contactFormResolveConditionalNotificationRecipients($savedForm, $fields, $input, $fallbackRecipient)
        : (($fallbackRecipient !== '' && filter_var($fallbackRecipient, FILTER_VALIDATE_EMAIL)) ? [$fallbackRecipient] : []);

    if ($notificationRecipients !== [] && function_exists('buildEmailTemplate') && function_exists('sendEmail')) {
        $subjectPrefix = trim((string) ($settings['email_subject'] ?? 'New Contact Form Submission'));
        $subjectSuffix = $savedForm
            ? trim((string) ($savedForm['name'] ?? 'Saved Form'))
            : ($summaryName !== '' ? $summaryName : 'Submission');
        $subject = trim($subjectPrefix . ' - ' . $subjectSuffix);
        $sentFrom = $_SERVER['HTTP_HOST'] ?? 'Unknown';
        $content = contactFormBuildNotificationContent($records, (string) $sentFrom, $savedForm);
        $body = buildEmailTemplate('New Contact Form Submission', $content);

        foreach ($notificationRecipients as $recipientEmail) {
            try {
                sendEmail($recipientEmail, $subject, $body);
            } catch (Throwable $e) {
                write_log('contact-form: notification email failed: ' . $e->getMessage(), 'error', [
                    'recipient' => $recipientEmail,
                    'form_id' => $savedForm ? (int) ($savedForm['id'] ?? 0) : null,
                ]);
            }
        }
    }

    if ($savedForm) {
        $confirmation = contactFormResolveConditionalConfirmation($savedForm, $fields, $input, $responseMessage);
        $responseMessage = trim((string) ($confirmation['message'] ?? $responseMessage));

        $responsePayload = ['message' => $responseMessage !== '' ? $responseMessage : 'Thank you for your message.'];
        $redirectUrl = trim((string) ($confirmation['redirect_url'] ?? ''));
        if ($redirectUrl !== '') {
            $responsePayload['redirect_url'] = $redirectUrl;
        }

        contactFormJsonOk($responsePayload);
    }

    contactFormJsonOk(['message' => $responseMessage]);
}
