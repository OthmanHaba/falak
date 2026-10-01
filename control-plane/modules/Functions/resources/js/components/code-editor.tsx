import { useAppearance } from '@/hooks/use-appearance';
import * as monaco from 'monaco-editor';
import EditorWorker from 'monaco-editor/editor/editor.worker.js?worker';
import TsWorker from 'monaco-editor/language/typescript/ts.worker.js?worker';
import { useEffect, useRef } from 'react';
import { BUN_HONO_TYPES } from './runtime-types';

/**
 * Monaco, bundled with the app (no CDN) and loaded only by the function tabs (React.lazy). TypeScript runs in its
 * worker with ambient Bun + Hono types, so autocomplete works; unknown npm imports are not reported (the server
 * installs them at deploy).
 */
let configured = false;

function configure() {
    if (configured) return;
    configured = true;

    self.MonacoEnvironment = {
        getWorker: (_id: string, label: string) => (label === 'typescript' || label === 'javascript' ? new TsWorker() : new EditorWorker()),
    };

    const ts = monaco.typescript;
    ts.typescriptDefaults.setCompilerOptions({
        target: ts.ScriptTarget.ESNext,
        module: ts.ModuleKind.ESNext,
        moduleResolution: ts.ModuleResolutionKind.NodeJs,
        allowNonTsExtensions: true,
        allowJs: true,
        strict: false,
        noEmit: true,
        skipLibCheck: true,
        lib: ['esnext', 'dom', 'dom.iterable'],
    });
    // 2307/2792/7016: modules without types (installed on the server); 1375/1378: top-level await; 2580: `require`.
    ts.typescriptDefaults.setDiagnosticsOptions({
        noSemanticValidation: false,
        noSyntaxValidation: false,
        diagnosticCodesToIgnore: [2307, 2792, 7016, 1375, 1378, 2580],
    });
    ts.typescriptDefaults.addExtraLib(BUN_HONO_TYPES, 'file:///node_modules/@types/kiln-function/index.d.ts');
}

const OPTIONS: monaco.editor.IStandaloneEditorConstructionOptions = {
    automaticLayout: true,
    fontSize: 13,
    fontFamily: 'var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace)',
    minimap: { enabled: false },
    scrollBeyondLastLine: false,
    tabSize: 4,
    renderWhitespace: 'selection',
    padding: { top: 12, bottom: 12 },
    fixedOverflowWidgets: true,
};

function languageOf(path: string, fallback: string): string {
    if (/\.(ts|mts|cts)$/.test(path)) return 'typescript';
    if (/\.(js|mjs|cjs)$/.test(path)) return 'javascript';
    if (path.endsWith('.json')) return 'json';
    if (path.endsWith('.py')) return 'python';

    return fallback;
}

export interface CodeEditorProps {
    path: string;
    value: string;
    language: string;
    readOnly?: boolean;
    onChange?: (value: string) => void;
    /** ⌘S / Ctrl+S */
    onSave?: () => void;
    className?: string;
}

export default function CodeEditor({ path, value, language, readOnly = false, onChange, onSave, className }: CodeEditorProps) {
    const host = useRef<HTMLDivElement>(null);
    const editor = useRef<monaco.editor.IStandaloneCodeEditor | null>(null);
    const handlers = useRef({ onChange, onSave });
    handlers.current = { onChange, onSave };
    const { resolved } = useAppearance();

    useEffect(() => {
        configure();
        if (!host.current) return;

        const uri = monaco.Uri.parse(`file:///${path}`);
        const model = monaco.editor.getModel(uri) ?? monaco.editor.createModel(value, languageOf(path, language), uri);
        if (model.getValue() !== value) model.setValue(value);

        const instance = monaco.editor.create(host.current, { ...OPTIONS, model, readOnly, theme: resolved === 'dark' ? 'vs-dark' : 'vs' });
        editor.current = instance;
        const changes = instance.onDidChangeModelContent(() => handlers.current.onChange?.(instance.getValue()));
        instance.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => handlers.current.onSave?.());

        return () => {
            changes.dispose();
            instance.dispose();
            model.dispose();
            editor.current = null;
        };
        // The model is recreated only when the file changes; `value` updates are applied below.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [path]);

    useEffect(() => {
        const model = editor.current?.getModel();
        if (model && model.getValue() !== value) model.setValue(value);
    }, [value]);

    useEffect(() => editor.current?.updateOptions({ readOnly }), [readOnly]);
    useEffect(() => monaco.editor.setTheme(resolved === 'dark' ? 'vs-dark' : 'vs'), [resolved]);

    return <div ref={host} className={className} />;
}

export interface DiffViewProps {
    path: string;
    original: string;
    modified: string;
    language: string;
    className?: string;
}

/** Read-only side-by-side diff (version history, deploy conflicts). */
export function DiffView({ path, original, modified, language, className }: DiffViewProps) {
    const host = useRef<HTMLDivElement>(null);
    const { resolved } = useAppearance();

    useEffect(() => {
        configure();
        if (!host.current) return;

        const lang = languageOf(path, language);
        const left = monaco.editor.createModel(original, lang);
        const right = monaco.editor.createModel(modified, lang);
        const diff = monaco.editor.createDiffEditor(host.current, {
            ...OPTIONS,
            readOnly: true,
            originalEditable: false,
            renderSideBySide: true,
            theme: resolved === 'dark' ? 'vs-dark' : 'vs',
        });
        diff.setModel({ original: left, modified: right });

        return () => {
            diff.dispose();
            left.dispose();
            right.dispose();
        };
    }, [path, original, modified, language, resolved]);

    return <div ref={host} className={className} />;
}
