import type { MentionNodeAttrs } from '@tiptap/extension-mention';
import type { SuggestionOptions } from '@tiptap/suggestion';
import { VueRenderer } from '@tiptap/vue-3';
import MentionList from '@/components/mail/MentionList.vue';
import type { UserSummary } from '@/types';

export function mentionSuggestion(
    members: () => UserSummary[],
): Omit<SuggestionOptions<UserSummary, MentionNodeAttrs>, 'editor'> {
    return {
        char: '@',
        items: ({ query }) =>
            members()
                .filter(
                    (member) =>
                        member.name
                            .toLowerCase()
                            .includes(query.toLowerCase()) ||
                        member.email
                            .toLowerCase()
                            .includes(query.toLowerCase()),
                )
                .slice(0, 6),
        render: () => {
            let component: VueRenderer | null = null;
            let popup: HTMLDivElement | null = null;

            const position = (rect: DOMRect | null) => {
                if (!popup || !rect) {
                    return;
                }

                popup.style.left = `${rect.left}px`;
                popup.style.top = `${rect.bottom + 6}px`;
            };

            return {
                onStart: (props) => {
                    component = new VueRenderer(MentionList, {
                        props,
                        editor: props.editor,
                    });
                    popup = document.createElement('div');
                    popup.className = 'fixed z-[60]';
                    popup.appendChild(component.element as HTMLElement);
                    document.body.appendChild(popup);
                    position(props.clientRect?.() ?? null);
                },
                onUpdate: (props) => {
                    component?.updateProps(props);
                    position(props.clientRect?.() ?? null);
                },
                onKeyDown: (props) => {
                    if (props.event.key === 'Escape') {
                        popup?.remove();

                        return true;
                    }

                    return (
                        (
                            component?.ref as {
                                onKeyDown?: (p: {
                                    event: KeyboardEvent;
                                }) => boolean;
                            } | null
                        )?.onKeyDown?.(props) ?? false
                    );
                },
                onExit: () => {
                    popup?.remove();
                    component?.destroy();
                    component = null;
                    popup = null;
                },
            };
        },
    };
}
