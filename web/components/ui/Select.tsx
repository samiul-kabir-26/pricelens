import type { ComponentProps, ReactNode } from "react";
import { cn } from "@/lib/format";
import { Field, controlClasses } from "./Input";

export interface SelectOption {
  value: string;
  label: string;
}

interface SelectFieldProps extends Omit<ComponentProps<"select">, "id"> {
  label: ReactNode;
  options: readonly SelectOption[];
  error?: string;
  hint?: ReactNode;
  containerClassName?: string;
}

export function SelectField({ label, options, error, hint, containerClassName, className, ...rest }: SelectFieldProps) {
  return (
    <Field label={label} error={error} hint={hint} className={containerClassName}>
      {({ id, describedBy, invalid }) => (
        <select
          id={id}
          aria-describedby={describedBy}
          aria-invalid={invalid || undefined}
          className={cn(controlClasses, "pr-8", className)}
          {...rest}
        >
          {options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      )}
    </Field>
  );
}
