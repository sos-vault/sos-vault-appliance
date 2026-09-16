import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography'

export default {
    darkMode: 'class',
    theme: {
        extend: {
            animation: {
                'marquee': 'marquee 25s linear infinite',
            },
            keyframes: {
                'marquee': {
                    from: { transform: 'translateX(0)' },
                    to: { transform: 'translateX(-100%)' },
                }
            }
        },
    },
    plugins: [forms, typography],
};
