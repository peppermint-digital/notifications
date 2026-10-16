import * as React from 'react';
import { cn } from './utils';

/**
 * Ein kleiner Knopf, damit das Paket nicht auf die shadcn-Komponenten des
 * aufnehmenden Produkts zugreifen muss.
 *
 * Bewusst KEINE Abhaengigkeit auf `@/components/ui/button`: Das Paket laeuft
 * in vier Produkten, und ein Importpfad auf deren Quellbaum waere in jedem
 * davon ein anderer. Die Klassen folgen trotzdem den shadcn-Farbtokens, damit
 * der Knopf sich in jedem Thema einfuegt.
 */
type Variant = 'ghost' | 'outline' | 'destructive' | 'primary';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
    /** Nur ein Symbol, quadratisch — fuer die Knopfreihe an einer Zeile. */
    icon?: boolean;
}

const VARIANTEN: Record<Variant, string> = {
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
    destructive: 'text-destructive hover:bg-destructive/10 hover:text-destructive',
    // Der Ein-Zustand eines Filters. Ein Filterknopf ohne gefuellte Variante
    // sieht im Ein- wie im Aus-Zustand gleich aus — und dann weiss niemand,
    // warum die Liste kuerzer ist als erwartet.
    primary: 'bg-primary text-primary-foreground hover:bg-primary/90',
};

export function Button({ variant = 'ghost', icon = false, className, ...props }: ButtonProps) {
    return (
        <button
            type="button"
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-md text-sm font-medium transition-colors',
                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                'disabled:pointer-events-none disabled:opacity-50',
                icon ? 'h-7 w-7 p-0' : 'h-8 px-2.5',
                VARIANTEN[variant],
                className,
            )}
            {...props}
        />
    );
}
