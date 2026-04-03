ALTER TABLE contact_form_fields
    ADD COLUMN conditional_logic JSON DEFAULT NULL AFTER options_text;