<?php

declare(strict_types=1);

return [
    // Filter bar
    'search_placeholder'    => 'Patient name or code...',
    'period_today'          => 'Today',
    'period_7'              => 'Last 7 days',
    'period_15'             => 'Last 15 days',
    'period_30'             => 'Last 30 days',
    'period_90'             => 'Last 90 days',
    'filters_btn'           => 'Filters',
    'eye_label'             => 'Eye:',
    'all'                   => 'All',
    'all_exams'             => 'All exams',
    'all_statuses'          => 'All statuses',
    'status_requested'      => 'Requested',
    'status_done'           => 'Done',
    'status_reported'       => 'Reported',
    'status_pending_report' => 'Not reported',
    'status_cancelled'      => 'Cancelled',
    'all_doctors'           => 'All doctors',
    'clear_btn'             => 'Clear',

    // Sidebar
    'patients_title'        => 'Patients',
    'no_patients'           => 'No patients.',
    'patients_count_suffix' => 'patient(s)',

    // Main area
    'medical_record'      => 'Medical Record',
    'view_selected'       => 'View selected',
    'view_all'            => 'View all',
    'print_btn'           => 'Print',
    'selected_suffix'     => 'selected',
    'select_patient_hint' => 'Select a patient on the sidebar to view their exams.',
    'loading_images'      => 'Loading images…',
    'no_exams'            => 'No exams for the selected filters.',
    'upload_btn'          => 'Upload',
    'download_btn'        => 'Download',
    'panel_prefix'        => 'Panel ',
    'no_image'            => 'No image',
    'not_found'           => 'Not found',

    // Print modal
    'portrait'    => 'Portrait',
    'landscape'   => 'Landscape',
    'close_btn'   => 'Close',
    'report_date' => 'Report date:',

    // AI — ocular image analysis
    'ai_analyze'          => 'Analyze with AI',
    'ai_selected_images'  => 'Selected images',
    'ai_no_selection'     => 'Select at least one image to analyze.',
    'ai_run_needs_images' => 'Select at least one image to run.',
    'ai_report'           => 'AI report',
    'ai_reported_badge'   => 'Reported (AI)',
    'download_pdf'        => 'Download PDF',

    // Manual report (Templates) — reuses the same catalog used by the
    // medical record's Documentations, filtered to reports/specialized exams.
    'report_doctor_required'     => 'Select a responsible doctor before issuing the report.',
    'report_default_title'       => 'Imaging Exam Report',
    'report_title'               => 'New report',
    'report_new'                 => 'New report',
    'report_templates'           => 'Templates',
    'report_template_blank'      => 'Blank',
    'report_loading_templates'   => 'Loading templates…',
    'report_no_templates'        => 'No templates available.',
    'report_content_label'       => 'Report content',
    'report_insert_image'        => 'Insert image',
    'report_phrases'             => 'Quick phrases',
    'report_phrases_empty'       => 'No saved phrases yet.',
    'report_phrases_save'        => 'Save selection as phrase',
    'report_phrases_save_hint'   => 'Select a piece of the text above before saving it as a phrase.',
    'report_phrases_label_title' => 'Phrase label',
    'report_phrases_saved'       => 'Phrase saved.',
    'report_phrases_deleted'     => 'Phrase removed.',
    'phrase_limit_reached'       => 'Quick phrase limit reached.',

    // Native equipment PDF text extraction — 09/18/2026 benchmark.
    'pdf_no_text_found'  => 'Could not extract text from any selected PDF.',
    'pdf_extract_text'   => 'Extract text from PDF',
    'pdf_extracting'     => 'Extracting text from PDF…',
    'pdf_extract_failed' => 'Could not extract text from the PDF.',
    // Clinical indication, observations and alternative date (Phase 5).
    'report_clinical_indication_label'       => 'Clinical indication',
    'report_clinical_indication_placeholder' => 'Reason for the exam/report…',
    'report_observations_label'              => 'Observations',
    'report_observations_placeholder'        => 'Additional observations…',

    'report_title_placeholder'   => 'Report title (optional)',
    'report_save'                => 'Save report',
    'report_saved'               => 'Report saved successfully.',
    'report_save_failed'         => 'Could not save the report.',
    'report_confirm_open_record' => 'There is no medical record for the visit date. Open a new record to save the report?',
    'report_content_required'    => 'Write the report content before saving.',
    'report_inactive_exam'       => 'One or more selected images are disabled — enable them before generating the report.',

    'merge_split_same_patient' => 'You can only merge/split images from the same patient.',
    'merge_action'             => 'Merge exams',
    'merge_select_two'         => 'Select 2 or more images from the same patient to merge.',
    'merge_success'            => 'Images merged into the same exam.',
    'split_action'             => 'Split exam',
    'split_select_one'         => 'Select at least 1 image from the group to split off.',
    'split_success'            => 'Image(s) split into a new exam.',
    'merged_badge'             => 'Merged',
    'undo_merge_split'         => 'Manually merged/split — click to undo (back to automatic grouping).',

    // Group by Equipment (default) / Group by Exam — 09/18/2026 benchmark
    'group_by_equipment' => 'Group by Equipment',
    'group_by_exam'      => 'Group by Exam',

    // Montage (image collage) — 09/18/2026 benchmark.
    'montage_action'         => 'Montage',
    'montage_select_two'     => 'Select 2 or more images to build the montage.',
    'montage_unavailable'    => 'Feature unavailable in this environment (Imagick extension not installed on the server).',
    'montage_no_images'      => 'None of the selected images could be read to build the montage.',
    'montage_failed'         => 'Could not build the montage.',
    'montage_video_excluded' => ':count video(s) were not included in the montage.',

    // Printing: reorder/group OD-OE/ad hoc caption (Phase 5).
    'print_group_od_oe'         => 'Group OD/OE',
    'print_group_od_oe_hint'    => 'Groups each exam with OD before OE (side by side in per-sheet mode); removes manual reordering',
    'print_per_sheet'           => 'Per sheet',
    'print_per_sheet_hint'      => ':count image(s) per sheet, with page break',
    'print_sheet_of'            => 'Sheet :n of :total',
    'print_move_up'             => 'Move up',
    'print_move_down'           => 'Move down',
    'print_caption_placeholder' => 'Add caption (optional)',
    'print_video_excluded'      => ":count video(s) aren't included in print (open them in the main panel to watch).",

    // Images in the report: block embedded in the signed PDF (Phase 5 / 5b).
    'image_report_enabled_label'       => 'Include image gallery in the report',
    'image_report_columns_label'       => 'Columns:',
    'image_report_pair_od_oe'          => 'Group OD/OE',
    'image_report_add'                 => 'Add image',
    'image_report_empty'               => 'No image added yet.',
    'image_report_caption_placeholder' => 'Caption (optional)',
    'image_report_remove'              => 'Remove',
    'report_image_block_title'         => 'Images',

    // External exam import: file larger than allowed (Phase 5 / 6b).
    'import_file_too_large' => 'The file exceeds the maximum allowed size (:max_mb MB).',

    // External exam import: outdated form sending one source for the whole batch (review round 13).
    'import_source_outdated' => 'The import page is out of date. Reload the page and try importing again.',

    // Changing patient in the inline search with a pending file/capture
    // (Phase 5 / adversarial Codex review, round 6).
    'import_patient_change_discards_files' => 'Switching patients now will discard the files and captures already added to this batch. Continue?',
    'import_patient_change_confirm'        => 'Switch patient',

    // Browser camera capture (Phase 5 / 6a).
    'capture_tab_upload'             => 'Upload file',
    'capture_tab_camera'             => 'Capture from camera',
    'capture_device_label'           => 'Camera',
    'capture_start_button'           => 'Turn on camera',
    'capture_photo_button'           => 'Photo',
    'capture_record_button'          => 'Record video',
    'capture_stop_button'            => 'Stop recording',
    'capture_recording_label'        => 'Recording… :seconds s',
    'capture_photo_taken'            => 'Photo captured.',
    'capture_video_recorded'         => 'Video captured.',
    'capture_permission_denied'      => 'Camera permission denied. Allow camera access in your browser settings and try again.',
    'capture_not_found'              => 'No camera found on this device.',
    'capture_not_supported'          => 'Camera capture requires a secure connection (HTTPS) and is not supported in this browser.',
    'capture_generic_error'          => 'Could not access the camera.',
    'capture_recording_in_progress'  => 'Stop the recording before importing.',
    'capture_finalizing_in_progress' => 'Wait for the capture to finish before importing.',
    'capture_item_laterality_fix'    => 'Click to correct this file\'s eye',

    // Compare / Align (progression across exams)
    'compare_title'             => 'Compare exams',
    'compare_action'            => 'Compare',
    'compare_select_two'        => 'Select exactly 2 images to compare.',
    'compare_mode_overlay'      => 'Overlay',
    'compare_mode_side_by_side' => 'Side by side',
    'compare_opacity'           => 'Opacity',
    'compare_reset'             => 'Reset position',
    'compare_hint'              => 'Drag the top image to align reference points.',

    // Import external exam
    'import' => [
        'success' => 'Exam imported successfully.',
    ],

    // Context menu (right-click on thumbnail) — competitor benchmark 2026-09-09
    'context_menu_title'          => 'Quick actions',
    'context_menu_report'         => 'Make manual report',
    'context_menu_compare'        => 'Compare / Align',
    'context_menu_share'          => 'Share with patient',
    'context_menu_unshare'        => 'Revoke from Patient Portal',
    'context_menu_download'       => 'Download image',
    'context_menu_eye'            => 'Eye',
    'context_menu_quality'        => 'Capture quality',
    'context_menu_quality_hint'   => 'Click to rate — click the same star again to clear',
    'context_menu_disable'        => 'Disable image',
    'context_menu_enable'         => 'Enable image',
    'context_menu_info_type'      => 'Type',
    'context_menu_info_created'   => 'Captured at',
    'context_menu_info_equipment' => 'Equipment',
    'context_menu_info_doctor'    => 'Doctor',
    'context_menu_info_origin'    => 'Origin',

    // Single report covering several exams of the same patient
    'eye_od'                  => 'OD',
    'eye_oe'                  => 'OS',
    'eye_ao'                  => 'OU',
    'report_exams_title'      => 'Exams included in this report',
    'report_exams_images'     => ':count image|:count images',
    'report_same_patient'     => 'All exams in a report must belong to the same patient.',
    'report_exams_label'      => 'Exams in this report',
    'report_selected'         => 'Report selected',
    'viewer_report'           => 'Report',
    'report_exams_hint'       => 'check which ones to include',
    'report_select_exam'      => 'Check at least one exam to report.',
    'viewer_report_open_hint' => 'Report open: check the exam in the report list',
    'viewer_exam_type'        => 'Exam type',
    'viewer_exam_date'        => 'Exam date',
    'viewer_patient_id'       => 'ID',
    'age_years'               => ':years years',
    'close'                   => 'Close',

    // Report draft (secretary-drafts / doctor-issues flow — Phase 5).
    'draft_status_draft'                => 'Draft',
    'draft_status_submitted'            => 'Sent to doctor',
    'draft_status_issued'               => 'Issued',
    'draft_status_discarded'            => 'Discarded',
    'draft_status_returned'             => 'Returned to technician',
    'draft_return'                      => 'Return to technician',
    'draft_return_title'                => 'Return to the technician?',
    'draft_return_reason_label'         => 'What needs to be adjusted?',
    'draft_return_reason_placeholder'   => 'E.g.: the OS image is missing; check the laterality.',
    'draft_return_confirm'              => 'Return',
    'draft_return_unsaved'              => 'Changes made on this screen will not be sent to the technician.',
    'draft_returned'                    => 'Draft returned to the technician.',
    'draft_return_failed'               => 'Could not return the draft.',
    'draft_return_not_submitted'        => 'Only a draft that is waiting for the doctor can be returned.',
    'draft_return_reason_required'      => 'Enter the reason for returning it.',
    'draft_returned_banner'             => 'Returned by the doctor:',
    'draft_resubmit'                    => 'Resubmit to the doctor',
    'drafts_only_mine_doctor'           => 'Only mine',
    'drafts_only_mine_preparer'         => 'Only the ones I prepared',
    'draft_for_doctor'                  => 'For :doctor',
    'drafts_returned_badge_hint'        => 'Drafts returned by the doctor for adjustment',
    'draft_mail_greeting'               => 'Hello, :name!',
    'draft_mail_submitted_subject'      => '[:clinic] Report waiting for your release',
    'draft_mail_submitted_line'         => 'An image report draft is waiting for your review and release in the Image Manager.',
    'draft_mail_returned_subject'       => '[:clinic] Report draft returned for adjustment',
    'draft_mail_returned_line'          => 'The doctor returned an image report draft you prepared. The reason is on the draft itself, in the Image Manager.',
    'draft_mail_action'                 => 'Open Image Manager',
    'draft_mail_salutation'             => '— :clinic',
    'dictation_start'                   => 'Dictate',
    'dictation_start_hint'              => 'Dictate the report; the text goes where the cursor is.',
    'dictation_stop'                    => 'Stop',
    'dictation_cancel'                  => 'Cancel dictation',
    'dictation_recording'               => 'Recording… :time',
    'dictation_sending'                 => 'Transcribing…',
    'dictation_notice'                  => 'The audio is sent to an approved AI provider to be turned into text and is not stored. Do not say the patient\'s name.',
    'dictation_mic_denied'              => 'No microphone access. Allow the microphone in the browser to dictate.',
    'dictation_unsupported'             => 'This browser cannot record audio for dictation.',
    'dictation_failed'                  => 'Could not transcribe the dictation.',
    'dictation_unavailable'             => 'Voice dictation is not available right now.',
    'dictation_invalid_audio'           => 'The audio sent is not valid or was too short. Record again.',
    'dictation_too_long'                => 'The dictation went over the :seconds-second limit. Dictate in shorter parts.',
    'dictation_no_speech'               => 'No speech recognized. Check the microphone and try again.',
    'dictation_provider_failed'         => 'The transcription service did not respond. Try again shortly.',
    'dictation_audit_failed'            => 'The audit record for sending the audio could not be saved; nothing was sent. Try again.',
    'dictation_audit_reason'            => 'Report dictation audio sent for transcription.',
    'draft_not_editable'                => 'This draft has already been issued or discarded and can no longer be changed.',
    'draft_revision_conflict'           => 'This draft was changed by someone else while you were reviewing it. Close and reopen it to see the latest version before saving or issuing.',
    'draft_new'                         => 'Prepare draft',
    'draft_selected'                    => 'Prepare draft for selected',
    'draft_save'                        => 'Save draft',
    'draft_saved'                       => 'Draft saved.',
    'draft_submit'                      => 'Send to doctor',
    'draft_submitted'                   => 'Draft sent to the doctor.',
    'draft_submitted_with_pending_edit' => 'Draft sent, but there is a more recent edit that has not been saved yet. Save again before closing.',
    'draft_review_issue'                => 'Review and issue',
    'draft_discard'                     => 'Discard draft',
    'draft_discard_title'               => 'Discard this draft?',
    'draft_discard_text'                => 'The draft will be discarded and can no longer be edited or issued.',
    'draft_discard_confirm'             => 'Discard draft',
    'draft_discarded'                   => 'Draft discarded.',
    'drafts_button'                     => 'Drafts',
    'drafts_modal_title'                => 'Report drafts',
    'drafts_empty'                      => 'No drafts here yet.',
    'drafts_load_more'                  => 'Load more',
    'drafts_filter_all'                 => 'All',
    'drafts_load_failed'                => 'Could not load the drafts.',
    'draft_review_issue_open'           => 'Open',

    // Non-blocking warning: the doctor who previewed the report (draft) is
    // different from whoever is issuing it now (Phase 5 / Codex review).
    'report_physician_mismatch_warning'        => "This report's text was generated based on :doctor's data. Review whether the physician named in the text is correct before signing.",
    'report_physician_mismatch_unknown_doctor' => 'another physician',
    'report_physician_mismatch_confirm'        => 'Issue anyway',

    // Non-blocking warning: a selected image for the report could not be
    // included now (exam disabled/deleted, file unavailable in S3, or byte
    // budget exhausted) — found in the 2nd round of adversarial Codex review.
    'report_image_incomplete_warning' => '{1} 1 of the selected images could not be included in the report (exam disabled, file unavailable, or deleted) and will be omitted. Review before issuing.|[2,*] :count of the selected images could not be included in the report (exam disabled, file unavailable, or deleted) and will be omitted. Review before issuing.',
    'report_image_incomplete_confirm' => 'Issue anyway',
    // Image in the embedded block referencing an exam outside the main
    // selection (exam_ids) — never silently dropped (found via adversarial
    // Codex review).
    'report_image_not_selected' => 'One of the report images belongs to an exam that is no longer selected. Refresh the image selection and try again.',
    // Non-blocking warning: inline <img> reference ("Insert image" button,
    // distinct from the block above) could not be resolved now (exam
    // disabled/deleted, file unavailable) — found via Codex adversarial
    // review.
    'report_inline_image_incomplete_warning' => '{1} 1 of the images inserted in the text could not be included in the report (exam disabled, file unavailable, or deleted) and will be removed. Review before issuing.|[2,*] :count of the images inserted in the text could not be included in the report (exam disabled, file unavailable, or deleted) and will be removed. Review before issuing.',
    // Hard block (never "issue anyway" — found via Codex adversarial
    // review): the same image inserted multiple times in the text
    // multiplies the expected weight every time the report is reopened or
    // printed again, even though resolution is only charged once per exam.
    'report_inline_images_too_large' => 'The images inserted in the report text exceed the size limit (the same image repeated several times adds its weight each time). Remove the repetitions before saving.',

    // Requesting doctor + email delivery (Phase 5).
    'requesting_doctor_label'             => 'Requesting doctor',
    'requesting_doctor_name_label'        => 'Requesting doctor name',
    'requesting_doctor_name_placeholder'  => "Doctor's name (optional)",
    'requesting_doctor_email_label'       => 'Requesting doctor email',
    'requesting_doctor_email_placeholder' => 'To send the report by email',
    'requesting_doctor_default_name'      => 'Doctor',
    'requesting_doctor_sent_at'           => 'Sent on :date',
    'requesting_doctor_not_sent'          => 'Not sent yet',
    'requesting_doctor_resend'            => 'Resend',
    'requesting_doctor_resent'            => 'Report resent to the requesting doctor.',
    'requesting_doctor_resend_failed'     => 'Could not resend the report.',
    'report_resend_no_email'              => 'This report has no requesting doctor email on file.',
    // The report only leaves the clinic once the record is SIGNED (immutable) — adversarial review round 15.
    'report_resend_record_not_signed'      => 'Sending to the requesting doctor is only released after the medical record is signed: the report must be finalized (immutable) before it leaves the clinic.',
    'external_report_not_final'            => 'This report has not been finalized by the clinic yet. Please try again later.',
    'requesting_doctor_awaiting_signature' => 'Recipient saved. Sending to the requesting doctor is released after the medical record is signed — send it from the record\'s Documents list.',
    'requesting_doctor_email_hint'         => 'Email delivery is only released after the medical record is signed.',
    'requesting_doctor_mail_subject'       => '[:clinic] Exam report available',
    'requesting_doctor_mail_greeting'      => 'Hello, :doctor!',
    'requesting_doctor_mail_line1'         => 'Clinic **:clinic** has made the imaging exam report for patient **:patient**, which you requested, available.',
    'requesting_doctor_mail_action'        => 'View report',
    'requesting_doctor_mail_expires'       => 'This link expires in :days days.',
    'requesting_doctor_mail_disclaimer'    => "If you weren't expecting this email, you can safely ignore it.",
    'requesting_doctor_mail_salutation'    => 'Best regards — :clinic',

    // USB pedal (Phase 5 / 7a/7b) — key mapping (the pedal "acts like a
    // keyboard"). The same "advance" key switches eye (capture) and moves
    // to the next image (viewer).
    'pedal_keys_must_differ'  => 'The capture key and the advance key for the pedal must be different.',
    'pedal_shortcuts_help'    => 'If you use a USB pedal (it works like a keyboard), choose here which keys it sends.',
    'pedal_capture_key_label' => 'Pedal — capture',
    'pedal_advance_key_label' => 'Pedal — advance (eye while capturing, next image in the viewer)',
    'pedal_key_enter'         => 'Enter',
    'pedal_key_space'         => 'Space',
    'pedal_key_pagedown'      => 'Page Down',
    'pedal_key_pageup'        => 'Page Up',

    // Opening a draft from the list (Phase 5 / Codex review) — the list only
    // returns a summary; opening needs the full draft fetched first.
    'draft_load_failed'               => 'Could not load the draft. Please try again.',
    'exam_fallback_label'             => 'Image',
    'draft_unavailable_exams_removed' => 'This draft had exam(s) that are no longer available (removed or deactivated) — they were automatically dropped from this report.',

    // Textos que as telas já pediam com tt('chave', 'padrão em português') e que não existiam
    // (em inglês a tela mostrava o padrão em português); EyeImagesViewTextsTest garante a paridade.
    'unshare_confirm_title'            => 'Revoke sharing?',
    'unshare_confirm_text'             => 'The patient loses access to this image in the Patient Portal.',
    'unshare_confirm_btn'              => 'Revoke',
    'merge_confirm_title'              => 'Merge exams?',
    'merge_confirm_text'               => 'The :count selected images will appear as a single exam.',
    'context_menu_open'                => 'More actions for this image',
    'context_menu_disable_confirm'     => 'Disabling this image blocks the report, PDF and AI analysis for it, and revokes sharing with the patient in the Portal, if any. Continue?',
    'context_menu_disable_confirm_btn' => 'Disable',
    'report_templates_load_failed'     => 'Could not load the templates. You can write the report from scratch or close and try again.',
    'report_discard_title'             => 'Discard unsaved report?',
    'report_discard_text'              => 'What you typed will be lost.',
    'report_discard_confirm'           => 'Discard',
    'save'                             => 'Save',
    'cancel'                           => 'Cancel',
    'delete'                           => 'Delete',
    'dictation_starting'               => 'Getting ready…',
];
