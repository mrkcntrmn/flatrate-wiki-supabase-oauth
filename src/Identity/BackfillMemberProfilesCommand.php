<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\Console\AbstractCommand;
use Flarum\User\User;
use Symfony\Component\Console\Input\InputOption;

final class BackfillMemberProfilesCommand extends AbstractCommand
{
    public function __construct(
        private MemberProfileBackfillPlanner $planner,
        private MemberProfileStore $profiles
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('flatrate:member-numbers:backfill')
            ->setDescription('Plan or apply metadata-only FlatRate member-profile backfill')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the deterministic plan without writing')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply an accepted plan digest')
            ->addOption('plan-sha256', null, InputOption::VALUE_REQUIRED, 'Accepted dry-run digest required for --apply');
    }

    protected function fire(): void
    {
        $dryRun = (bool) $this->input->getOption('dry-run');
        $apply = (bool) $this->input->getOption('apply');
        if ($dryRun === $apply) {
            $this->error('Specify exactly one of --dry-run or --apply');

            return;
        }

        $users = User::query()
            ->orderBy('id')
            ->get(['id', 'nickname'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'nickname' => (string) ($user->nickname ?? ''),
            ])
            ->all();

        $plan = $this->planner->plan($users);
        $this->printSummary($plan);

        if ($dryRun) {
            return;
        }

        $expected = trim((string) $this->input->getOption('plan-sha256'));
        if ($expected === '') {
            $this->error('BACKFILL_PLAN_DRIFT');

            return;
        }

        $written = $this->profiles->applyPlan($plan, $expected);
        $this->info('BACKFILL_APPLIED_COUNT='.$written);
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function printSummary(array $plan): void
    {
        foreach ([
            'BACKFILL_USER_COUNT',
            'MEMBER_MODE_COUNT',
            'CUSTOM_MODE_COUNT',
            'GRANDFATHERED_COUNT',
            'HASH_MEMBER_NAMESPACE_COLLISION_COUNT',
            'VISIBLE_NICKNAME_MUTATION_COUNT',
            'BACKFILL_PLAN_SHA256',
        ] as $key) {
            $this->info($key.'='.$plan[$key]);
        }
    }
}
