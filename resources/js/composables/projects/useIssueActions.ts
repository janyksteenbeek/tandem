import { router, usePage } from '@inertiajs/vue3';
import { computed, inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import { toast } from 'vue-sonner';
import { api, HttpError, reportError } from '@/lib/http';
import { issueStore } from '@/lib/projects/issueStore';
import type {
    CompletionReadiness,
    Issue,
    IssueComment,
    IssueListItem,
    IssuePriority,
    WorkflowStatus,
    Workstream,
} from '@/types';

type IssueResponse = { issue: Issue };
type Optimistic = () => () => void;

export type CompletionBlocked = {
    readiness: CompletionReadiness;
    status: WorkflowStatus;
};

/**
 * Optimistic issue mutations. Every call patches the store immediately, posts to the server, then
 * reconciles with the returned issue (or rolls back and toasts on failure). Mirrors
 * useConversationActions for mail.
 */
export function useIssueActions(onUpdated?: (issue: Issue) => void) {
    const page = usePage();
    const slug = computed(() => page.props.currentOrganization?.slug ?? '');
    const base = (identifier: string) => `/${slug.value}/issues/${identifier}`;

    const run = async (
        request: () => Promise<IssueResponse>,
        optimistic?: Optimistic,
        success?: string,
    ): Promise<Issue | null> => {
        const rollback = optimistic?.();

        try {
            const data = await request();
            issueStore.upsert(data.issue, { force: true });
            onUpdated?.(data.issue);

            if (success) {
                toast.success(success);
            }

            return data.issue;
        } catch (error) {
            rollback?.();
            reportError(error);

            return null;
        }
    };

    const patch =
        (issue: IssueListItem, partial: Partial<Issue>): Optimistic =>
        () =>
            issueStore.patch(issue.uuid, partial);

    return {
        /**
         * Returns the blocked readiness when completion requires an override, so the caller can
         * offer "Complete anyway".
         */
        setStatus: async (
            issue: IssueListItem,
            status: WorkflowStatus,
            override = false,
            reason?: string,
        ): Promise<Issue | CompletionBlocked | null> => {
            const rollback = issueStore.patch(issue.uuid, {
                status,
                status_category: status.category,
            });

            try {
                const data = await api.post<IssueResponse>(
                    `${base(issue.identifier)}/status`,
                    { status_id: status.id, override, reason },
                );
                issueStore.upsert(data.issue, { force: true });
                onUpdated?.(data.issue);

                return data.issue;
            } catch (error) {
                rollback();

                if (
                    error instanceof HttpError &&
                    error.status === 422 &&
                    error.body.code === 'workstreams_incomplete'
                ) {
                    return {
                        readiness: error.body.readiness as CompletionReadiness,
                        status,
                    };
                }

                reportError(error);

                return null;
            }
        },

        setWorkstreamStatus: (
            issue: IssueListItem,
            workstream: Workstream,
            status: WorkflowStatus,
            force = false,
        ) =>
            run(
                () =>
                    api.post<IssueResponse>(
                        `${base(issue.identifier)}/workstreams/${workstream.uuid}/status`,
                        { status_id: status.id, force },
                    ),
                () => {
                    const next = {
                        ...workstream,
                        status,
                        updated_at: new Date().toISOString(),
                    };
                    const current = issueStore.get(issue.uuid) as
                        | Issue
                        | undefined;
                    const workstreams =
                        current && 'workstreams' in current
                            ? current.workstreams.map((w) =>
                                  w.uuid === workstream.uuid ? next : w,
                              )
                            : undefined;

                    return issueStore.patch(issue.uuid, {
                        workstream:
                            issue.workstream?.uuid === workstream.uuid
                                ? next
                                : issue.workstream,
                        ...(workstreams ? { workstreams } : {}),
                        teams: issue.teams.map((t) =>
                            t.id === workstream.team.id
                                ? {
                                      ...t,
                                      status_category: status.category,
                                      status_color: status.color,
                                      status_name: status.name,
                                  }
                                : t,
                        ),
                    });
                },
            ),

        setPriority: (issue: IssueListItem, priority: IssuePriority) =>
            run(
                () =>
                    api.post<IssueResponse>(
                        `${base(issue.identifier)}/priority`,
                        { priority },
                    ),
                patch(issue, { priority }),
            ),

        setOwner: (
            issue: IssueListItem,
            ownerId: number | null,
            owner: Issue['owner'] = null,
        ) =>
            run(
                () =>
                    api.post<IssueResponse>(`${base(issue.identifier)}/owner`, {
                        owner_id: ownerId,
                    }),
                patch(issue, { owner }),
            ),

        setLeadTeam: (issue: IssueListItem, teamId: number | null) =>
            run(() =>
                api.post<IssueResponse>(`${base(issue.identifier)}/lead-team`, {
                    team_id: teamId,
                }),
            ),

        setParent: (issue: IssueListItem, parentIdentifier: string | null) =>
            run(() =>
                api.post<IssueResponse>(`${base(issue.identifier)}/parent`, {
                    parent_identifier: parentIdentifier,
                }),
            ),

        setMilestone: (issue: IssueListItem, milestoneId: number | null) =>
            run(() =>
                api.post<IssueResponse>(`${base(issue.identifier)}/milestone`, {
                    milestone_id: milestoneId,
                }),
            ),

        setDates: (
            issue: IssueListItem,
            dates: { start_date?: string | null; due_date?: string | null },
        ) =>
            run(
                () =>
                    api.patch<IssueResponse>(base(issue.identifier), {
                        title: issue.title,
                        ...dates,
                    }),
                patch(issue, dates),
            ),

        setEstimate: (issue: IssueListItem, estimate: number | null) =>
            run(
                () =>
                    api.patch<IssueResponse>(base(issue.identifier), {
                        title: issue.title,
                        estimate,
                    }),
                patch(issue, { estimate }),
            ),

        rename: (issue: IssueListItem, title: string) =>
            run(
                () =>
                    api.patch<IssueResponse>(base(issue.identifier), { title }),
                patch(issue, { title }),
            ),

        setDescription: (issue: IssueListItem, html: string) =>
            run(
                () =>
                    api.patch<IssueResponse>(base(issue.identifier), {
                        title: issue.title,
                        description_html: html,
                    }),
                patch(issue, { description_html: html } as Partial<Issue>),
            ),

        toggleLabel: (
            issue: IssueListItem,
            labelId: number | null,
            attached: boolean,
            name?: string,
        ) =>
            run(() =>
                api.post<IssueResponse>(`${base(issue.identifier)}/labels`, {
                    label_id: labelId,
                    attached,
                    name,
                }),
            ),

        setModules: (issue: IssueListItem, moduleIds: number[]) =>
            run(() =>
                api.put<IssueResponse>(`${base(issue.identifier)}/modules`, {
                    module_ids: moduleIds,
                }),
            ),

        move: (
            issue: IssueListItem,
            productId: number | null,
            projectId: number | null,
        ) =>
            run(
                () =>
                    api.post<IssueResponse>(`${base(issue.identifier)}/move`, {
                        product_id: productId,
                        project_id: projectId,
                    }),
                undefined,
                'Issue moved',
            ),

        follow: (issue: IssueListItem, following: boolean) =>
            run(
                () =>
                    api.post<IssueResponse>(
                        `${base(issue.identifier)}/follow`,
                        { following },
                    ),
                patch(issue, { is_following: following } as Partial<Issue>),
            ),

        addTeam: (
            issue: IssueListItem,
            teamId: number,
            extra: {
                responsibility?: string | null;
                is_required?: boolean;
                assignee_ids?: number[];
            } = {},
        ) =>
            run(() =>
                api.post<IssueResponse>(`${base(issue.identifier)}/teams`, {
                    team_id: teamId,
                    ...extra,
                }),
            ),

        removeTeam: (issue: IssueListItem, workstream: Workstream) =>
            run(
                () =>
                    api.delete<IssueResponse>(
                        `${base(issue.identifier)}/workstreams/${workstream.uuid}`,
                    ),
                undefined,
                `${workstream.team.name} removed`,
            ),

        updateWorkstream: (
            issue: IssueListItem,
            workstream: Workstream,
            attributes: Partial<{
                responsibility: string | null;
                is_required: boolean;
                estimate: number | null;
                due_date: string | null;
                assignee_ids: number[];
            }>,
        ) =>
            run(() =>
                api.patch<IssueResponse>(
                    `${base(issue.identifier)}/workstreams/${workstream.uuid}`,
                    attributes,
                ),
            ),

        setAssignees: (
            issue: IssueListItem,
            workstream: Workstream,
            userIds: number[],
        ) =>
            run(() =>
                api.put<IssueResponse>(
                    `${base(issue.identifier)}/workstreams/${workstream.uuid}/assignees`,
                    { user_ids: userIds },
                ),
            ),

        setWorkstreamCycle: (
            issue: IssueListItem,
            workstream: Workstream,
            cycleId: number | null,
        ) =>
            run(() =>
                api.post<IssueResponse>(
                    `${base(issue.identifier)}/workstreams/${workstream.uuid}/cycle`,
                    { cycle_id: cycleId },
                ),
            ),

        addRelation: (
            issue: IssueListItem,
            identifier: string,
            kind: 'blocks' | 'blocked_by' | 'related' | 'duplicate',
        ) =>
            run(() =>
                api.post<IssueResponse>(`${base(issue.identifier)}/relations`, {
                    identifier,
                    kind,
                }),
            ),

        removeRelation: (issue: IssueListItem, relationId: number) =>
            run(() =>
                api.delete<IssueResponse>(
                    `${base(issue.identifier)}/relations/${relationId}`,
                ),
            ),

        addDependency: (
            issue: IssueListItem,
            workstream: Workstream,
            target: { workstream_uuid?: string; identifier?: string },
        ) =>
            run(() =>
                api.post<IssueResponse>(
                    `${base(issue.identifier)}/workstreams/${workstream.uuid}/dependencies`,
                    target,
                ),
            ),

        removeDependency: (
            issue: IssueListItem,
            workstream: Workstream,
            dependencyId: number,
        ) =>
            run(() =>
                api.delete<IssueResponse>(
                    `${base(issue.identifier)}/workstreams/${workstream.uuid}/dependencies/${dependencyId}`,
                ),
            ),

        destroy: async (issue: IssueListItem) => {
            try {
                await api.delete(base(issue.identifier));
                issueStore.remove(issue.uuid);
                toast.success(`${issue.identifier} deleted`);

                return true;
            } catch (error) {
                reportError(error);

                return false;
            }
        },

        bulk: async (
            uuids: string[],
            action: string,
            params: Record<string, unknown> = {},
        ) => {
            try {
                const data = await api.post<{
                    issues: (IssueListItem | { uuid: string; deleted: true })[];
                }>(`/${slug.value}/issues/bulk`, {
                    issues: uuids,
                    action,
                    ...params,
                });

                for (const item of data.issues) {
                    if ('deleted' in item) {
                        issueStore.remove(item.uuid);
                    } else {
                        issueStore.upsert(item, { force: true });
                    }
                }

                toast.success(
                    `Updated ${data.issues.length} issue${data.issues.length === 1 ? '' : 's'}`,
                );

                return data.issues;
            } catch (error) {
                reportError(error);

                return [];
            }
        },

        comments: {
            create: async (
                issue: IssueListItem,
                body: string,
                mentions: number[],
                parentUuid?: string | null,
            ) => {
                try {
                    const data = await api.post<{ comment: IssueComment }>(
                        `${base(issue.identifier)}/comments`,
                        { body, mentions, parent_uuid: parentUuid ?? null },
                    );

                    return data.comment;
                } catch (error) {
                    reportError(error);

                    return null;
                }
            },
            update: async (
                comment: IssueComment,
                body: string,
                mentions: number[],
            ) => {
                try {
                    const data = await api.patch<{ comment: IssueComment }>(
                        `/${slug.value}/issue-comments/${comment.uuid}`,
                        { body, mentions },
                    );

                    return data.comment;
                } catch (error) {
                    reportError(error);

                    return null;
                }
            },
            remove: async (comment: IssueComment) => {
                try {
                    await api.delete(
                        `/${slug.value}/issue-comments/${comment.uuid}`,
                    );

                    return true;
                } catch (error) {
                    reportError(error);

                    return false;
                }
            },
            react: async (
                comment: IssueComment,
                emoji: string,
                on: boolean,
            ) => {
                try {
                    const data = await api.post<{ comment: IssueComment }>(
                        `/${slug.value}/issue-comments/${comment.uuid}/react`,
                        { emoji, on },
                    );

                    return data.comment;
                } catch (error) {
                    reportError(error);

                    return null;
                }
            },
        },

        refreshNav: () => router.reload({ only: ['projectsNav'] }),
    };
}

export type IssueActions = ReturnType<typeof useIssueActions>;

export const issueActionsKey: InjectionKey<IssueActions> =
    Symbol('issue-actions');

export function provideIssueActions(actions: IssueActions): void {
    provide(issueActionsKey, actions);
}

export function injectIssueActions(): IssueActions {
    const actions = inject(issueActionsKey, null);

    if (!actions) {
        throw new Error('injectIssueActions() must be used inside a provider.');
    }

    return actions;
}
