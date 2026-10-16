import * as React from 'react';
import { cn } from './utils';

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement> {
    variant?: 'secondary' | 'outline';
}

export function Badge({ variant = 'secondary', className, ...props }: BadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-1.5 py-0.5 text-[0.625rem] leading-none font-medium',
                variant === 'secondary'
                    ? 'bg-secondary text-secondary-foreground'
                    : 'border border-border text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}
