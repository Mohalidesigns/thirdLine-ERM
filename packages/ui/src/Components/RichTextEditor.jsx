import { useEffect, useRef } from 'react';
import EditorJS from '@editorjs/editorjs';
import Header from '@editorjs/header';
import EditorjsList from '@editorjs/list';
import Table from '@editorjs/table';
import Quote from '@editorjs/quote';
import CodeTool from '@editorjs/code';
import InlineCode from '@editorjs/inline-code';
import Underline from '@editorjs/underline';
import Delimiter from '@editorjs/delimiter';
import { toEditorJsData } from '../lib/richtext';

/**
 * Shared WYSIWYG editor wrapping Editor.js. The single rich-text entry point
 * for the app — do not instantiate Editor.js directly in forms.
 *
 * `value` accepts an Editor.js JSON string, legacy plain text, or ''.
 * `onChange` receives the serialized Editor.js JSON string on every edit.
 * `onBlurSave` (optional) fires once with the serialized content when focus
 * leaves the editor AND the content changed — the rich-text equivalent of
 * the save-on-blur textarea pattern used by the per-clause auditor notes.
 * Programmatic value changes from outside (e.g. the AI report writer) are
 * detected and re-rendered into the editor.
 */
export default function RichTextEditor({ value, onChange, onBlurSave, placeholder = 'Start writing...', minHeight = 120, readOnly = false }) {
    const holderRef = useRef(null);
    const editorRef = useRef(null);
    const lastEmittedRef = useRef(value ?? '');
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;
    const onBlurSaveRef = useRef(onBlurSave);
    onBlurSaveRef.current = onBlurSave;
    // Baseline for blur-save comparison; seeded with the editor's own
    // serialization after mount so wrapped legacy text isn't a false change.
    const blurBaselineRef = useRef(null);
    const debounceRef = useRef(null);

    const handleFocusOut = (e) => {
        if (!onBlurSaveRef.current) return;
        // Ignore focus moves that stay inside the editor (toolbar clicks etc.).
        if (e.currentTarget.contains(e.relatedTarget)) return;
        const editor = editorRef.current;
        if (!editor) return;
        editor.save().then((data) => {
            const json = data.blocks.length ? JSON.stringify(data) : '';
            if (blurBaselineRef.current !== null && json !== blurBaselineRef.current) {
                blurBaselineRef.current = json;
                onBlurSaveRef.current?.(json);
            }
        }).catch(() => {});
    };

    useEffect(() => {
        const editor = new EditorJS({
            holder: holderRef.current,
            data: toEditorJsData(value),
            placeholder,
            readOnly,
            minHeight,
            inlineToolbar: true,
            tools: {
                header: { class: Header, inlineToolbar: true, config: { levels: [2, 3, 4], defaultLevel: 3 } },
                list: { class: EditorjsList, inlineToolbar: true, config: { defaultStyle: 'unordered' } },
                table: { class: Table, inlineToolbar: true, config: { withHeadings: true } },
                quote: { class: Quote, inlineToolbar: true },
                code: CodeTool,
                inlineCode: InlineCode,
                underline: Underline,
                delimiter: Delimiter,
            },
            onChange: (api) => {
                clearTimeout(debounceRef.current);
                debounceRef.current = setTimeout(async () => {
                    try {
                        const data = await api.saver.save();
                        const json = data.blocks.length ? JSON.stringify(data) : '';
                        lastEmittedRef.current = json;
                        onChangeRef.current?.(json);
                    } catch {
                        // Editor was destroyed mid-save (page navigation) — ignore.
                    }
                }, 250);
            },
        });
        editorRef.current = editor;

        // Seed the blur-save baseline with the editor's own serialization of
        // the initial value (legacy text serializes to wrapped blocks).
        editor.isReady
            .then(() => editor.save())
            .then((data) => {
                blurBaselineRef.current = data.blocks.length ? JSON.stringify(data) : '';
            })
            .catch(() => { blurBaselineRef.current = ''; });

        return () => {
            clearTimeout(debounceRef.current);
            editor.isReady
                .then(() => editor.destroy())
                .catch(() => {});
            editorRef.current = null;
        };
        // The editor manages its own state after mount; value changes are
        // handled by the sync effect below.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Sync external value changes (AI generation, form resets) into the editor.
    useEffect(() => {
        const editor = editorRef.current;
        if (!editor) return;
        if ((value ?? '') === (lastEmittedRef.current ?? '')) return;

        lastEmittedRef.current = value ?? '';
        editor.isReady
            .then(() => {
                const data = toEditorJsData(value);
                return data.blocks.length ? editor.render(data) : editor.clear();
            })
            .catch(() => {});
    }, [value]);

    return (
        <div
            className="rich-text-editor form-input w-full !px-3 !py-2"
            style={{ minHeight }}
            onBlur={handleFocusOut}
        >
            <div ref={holderRef} />
        </div>
    );
}
