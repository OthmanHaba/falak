import { useAppearance } from '@/hooks/use-appearance';
import * as monaco from 'monaco-editor';
import EditorWorker from 'monaco-editor/editor/editor.worker.js?worker';
import TsWorker from 'monaco-editor/language/typescript/ts.worker.js?worker';
import { useEffect, useRef } from 'react';
import { languageOf } from '../files';
import type { FunctionFiles } from '../types';
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
        // Node and Deno import local files with their extension (`./lib/db.ts`).
        allowImportingTsExtensions: true,
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

export interface CodeEditorProps {
    /** Model URI prefix (file:///<root>/<path>), distinct per view so models never collide. */
    root: string;
    files: FunctionFiles;
    /** The file shown. */
    active: string;
    /** For files whose extension says nothing. */
    language: string;
    readOnly?: boolean;
    onChange?: (path: string, value: string) => void;
    /** ⌘S / Ctrl+S */
    onSave?: () => void;
    className?: string;
}

/**
 * One Monaco editor over every file of the function: a model per file (so TypeScript resolves `./lib/db` between
 * them), switching models when another file is opened and keeping each file's cursor and scroll position.
 */
export default function CodeEditor({ root, files, active, language, readOnly = false, onChange, onSave, className }: CodeEditorProps) {
    const host = useRef<HTMLDivElement>(null);
    const editor = useRef<monaco.editor.IStandaloneCodeEditor | null>(null);
    const models = useRef(new Map<string, monaco.editor.ITextModel>());
    const views = useRef(new Map<string, monaco.editor.ICodeEditorViewState | null>());
    const shown = useRef<string | null>(null);
    const applying = useRef(false);
    const handlers = useRef({ onChange, onSave });
    handlers.current = { onChange, onSave };
    const { resolved } = useAppearance();

    // Models follow the files: created, updated (changes from outside the editor only) and disposed with them.
    const sync = (next: FunctionFiles) => {
        for (const [path, value] of Object.entries(next)) {
            const model = models.current.get(path);
            if (!model) {
                const uri = monaco.Uri.parse(`file:///${root}/${path}`);
                monaco.editor.getModel(uri)?.dispose();
                models.current.set(path, monaco.editor.createModel(value, languageOf(path, language), uri));
            } else if (model.getValue() !== value) {
                applying.current = true;
                model.setValue(value);
                applying.current = false;
            }
        }
        for (const [path, model] of models.current) {
            if (!(path in next)) {
                model.dispose();
                models.current.delete(path);
                views.current.delete(path);
            }
        }
    };

    useEffect(() => {
        configure();
        if (!host.current) return;
        const all = models.current;
        sync(files);

        const instance = monaco.editor.create(host.current, {
            ...OPTIONS,
            model: all.get(active) ?? null,
            readOnly,
            theme: resolved === 'dark' ? 'vs-dark' : 'vs',
        });
        editor.current = instance;
        shown.current = active;
        const changes = instance.onDidChangeModelContent(() => {
            if (!applying.current && shown.current) handlers.current.onChange?.(shown.current, instance.getValue());
        });
        instance.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => handlers.current.onSave?.());

        return () => {
            changes.dispose();
            instance.dispose();
            all.forEach((model) => model.dispose());
            all.clear();
            editor.current = null;
        };
        // Created once per root; files and the active file are applied below.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [root]);

    useEffect(() => {
        sync(files);
        const instance = editor.current;
        if (!instance) return;
        const model = models.current.get(active) ?? null;
        if (shown.current !== active || instance.getModel() !== model) {
            if (shown.current && shown.current !== active) views.current.set(shown.current, instance.saveViewState());
            instance.setModel(model);
            const view = views.current.get(active);
            if (view) instance.restoreViewState(view);
            shown.current = active;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [files, active]);

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
