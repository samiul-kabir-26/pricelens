"use client";

import { useEffect, useId, useRef, useState, type DragEvent } from "react";
import { cn } from "@/lib/format";
import { ACCEPTED_IMAGE_TYPES, MAX_IMAGE_BYTES } from "@/lib/schemas";

interface ImageDropzoneProps {
  file: File | null;
  onFileChange: (file: File | null) => void;
  imageUrl: string;
  onImageUrlChange: (url: string) => void;
  fileError?: string;
  urlError?: string;
}

function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`;
  return `${(n / 1024 / 1024).toFixed(1)} MB`;
}

/** Drag-and-drop (or click/keyboard) image picker with preview, or an image URL. */
export function ImageDropzone({ file, onFileChange, imageUrl, onImageUrlChange, fileError, urlError }: ImageDropzoneProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);
  const [preview, setPreview] = useState<string | null>(null);
  const id = useId();

  useEffect(() => {
    if (!file || typeof URL.createObjectURL !== "function") return;
    const url = URL.createObjectURL(file);
    // eslint-disable-next-line react-hooks/set-state-in-effect -- syncing an external object URL
    setPreview(url);
    return () => {
      URL.revokeObjectURL(url);
      setPreview(null);
    };
  }, [file]);

  const pick = (files: FileList | null) => {
    const f = files?.[0];
    if (f) onFileChange(f);
  };

  const onDrop = (e: DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    setDragging(false);
    pick(e.dataTransfer.files);
  };

  return (
    <div className="space-y-3">
      <div>
        <span id={`${id}-label`} className="block text-sm font-medium text-fg">
          Product image
        </span>
        {file ? (
          <div className="mt-1 flex items-center gap-3 rounded-md border border-border bg-surface p-2">
            {preview ? (
              // eslint-disable-next-line @next/next/no-img-element -- local blob preview
              <img src={preview} alt="Selected product image preview" className="h-16 w-16 rounded object-cover" />
            ) : (
              <div className="h-16 w-16 rounded bg-surface-muted" aria-hidden="true" />
            )}
            <div className="min-w-0 flex-1 text-sm">
              <p className="truncate font-medium text-fg">{file.name}</p>
              <p className="text-fg-muted">{formatBytes(file.size)}</p>
            </div>
            <button
              type="button"
              onClick={() => {
                onFileChange(null);
                if (inputRef.current) inputRef.current.value = "";
              }}
              className="rounded-md px-2 py-1 text-sm text-fg-muted hover:bg-surface-muted hover:text-fg"
            >
              Remove
            </button>
          </div>
        ) : (
          <div
            onDragOver={(e) => {
              e.preventDefault();
              setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={onDrop}
            className={cn(
              "mt-1 flex flex-col items-center justify-center gap-1 rounded-md border border-dashed px-4 py-6 text-center text-sm transition-colors",
              dragging ? "border-accent bg-accent-soft" : "border-border bg-surface",
              fileError && "border-danger",
            )}
          >
            <p className="text-fg-muted">Drag an image here, or</p>
            <button
              type="button"
              onClick={() => inputRef.current?.click()}
              className="font-medium text-accent underline-offset-2 hover:underline"
              aria-describedby={`${id}-hint${fileError ? ` ${id}-err` : ""}`}
            >
              choose a file
            </button>
            <p id={`${id}-hint`} className="text-xs text-fg-muted">
              JPEG, PNG or WebP up to {formatBytes(MAX_IMAGE_BYTES)}
            </p>
          </div>
        )}
        <input
          ref={inputRef}
          type="file"
          accept={ACCEPTED_IMAGE_TYPES.join(",")}
          className="sr-only"
          tabIndex={-1}
          aria-labelledby={`${id}-label`}
          aria-hidden="true"
          data-testid="image-file-input"
          onChange={(e) => pick(e.target.files)}
        />
        {fileError && (
          <p id={`${id}-err`} role="alert" className="mt-1 text-xs text-danger">
            {fileError}
          </p>
        )}
      </div>

      {!file && (
        <div className="space-y-1">
          <label htmlFor={`${id}-url`} className="block text-sm font-medium text-fg">
            …or image URL
          </label>
          <input
            id={`${id}-url`}
            type="url"
            inputMode="url"
            placeholder="https://cdn.example.org/images/product.jpg"
            value={imageUrl}
            onChange={(e) => onImageUrlChange(e.target.value)}
            aria-invalid={urlError ? true : undefined}
            aria-describedby={urlError ? `${id}-url-err` : undefined}
            className="block w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg placeholder:text-fg-muted/70 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30 aria-[invalid=true]:border-danger"
          />
          {urlError && (
            <p id={`${id}-url-err`} role="alert" className="text-xs text-danger">
              {urlError}
            </p>
          )}
        </div>
      )}
    </div>
  );
}
