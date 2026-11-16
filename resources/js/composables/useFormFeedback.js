import { nextTick, ref, useId } from 'vue';

// Pages keep their field ids and validation rules. This supplies consistent
// error associations and focuses the rendered summary after a failed save.
export function useFormFeedback(form) {
    const errorSummary = ref(null);
    const uid = useId();
    const errorId = field => `${uid}-error-${field.replaceAll('.', '-')}`;
    const fieldErrorAttrs = (field, hintIds = '') => ({
        'aria-invalid': form.errors[field] ? true : undefined,
        'aria-describedby': [hintIds, form.errors[field] ? errorId(field) : ''].filter(Boolean).join(' ') || undefined,
    });
    const focusErrors = () => nextTick(() => errorSummary.value?.focus());
    return { errorSummary, errorId, fieldErrorAttrs, focusErrors };
}
