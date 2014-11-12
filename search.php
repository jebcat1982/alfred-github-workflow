<?php

require 'workflow.php';

$query = ltrim($argv[1]);
$parts = explode(' ', $query);

Workflow::init($query);

if (Workflow::checkUpdate()) {
    $cmds = array(
        'update' => 'There is an update for this Alfred workflow',
        'deactivate autoupdate' => 'Deactivate auto updating this Alfred Workflow'
    );
    foreach ($cmds as $cmd => $desc) {
        Workflow::addItem(Item::create()
            ->prefix('gh ')
            ->title('> ' . $cmd)
            ->subtitle($desc)
            ->arg('> ' . str_replace(' ', '-', $cmd))
            ->randomUid()
        , false);
    }
    print Workflow::getItemsAsXml();
    exit;
}

if (!Workflow::getConfig('access_token') || !($userData = Workflow::requestGithubApi('/user'))) {
    Workflow::removeConfig('access_token');
    $token = null;
    if (count($parts) > 1 && $parts[0] == '>' && $parts[1] == 'login' && isset($parts[2])) {
        $token = $parts[2];
    }
    if (!$token) {
        Workflow::addItem(Item::create()
            ->prefix('gh ')
            ->title('> login')
            ->subtitle('Generate OAuth access token')
            ->arg('> login')
            ->randomUid()
        , false);
    }
    Workflow::addItem(Item::create()
        ->prefix('gh ')
        ->title('> login ' . $token)
        ->subtitle('Save OAuth access token')
        ->arg('> login ' . $token)
        ->valid((bool) $token, '<access_token>')
        ->randomUid()
    , false);
    print Workflow::getItemsAsXml();
    return;
}

Workflow::stopServer();

$isSystem = isset($query[0]) && $query[0] == '>';
$isMy = 'my' == $parts[0] && isset($parts[1]);
$isUser = isset($query[0]) && $query[0] == '@';
$isRepo = false;
$queryUser = null;
if ($isUser) {
    $queryUser = ltrim($parts[0], '@');
} elseif (($pos = strpos($parts[0], '/')) !== false) {
    $queryUser = substr($parts[0], 0, $pos);
    $isRepo = true;
}

if (!$isSystem) {

    if (!$isUser && !$isMy && $isRepo && isset($parts[1])) {

        if (false && isset($parts[1][0]) && in_array($parts[1][0], array('#', '@', '/'))) {

            $compareDescription = false;
            $pathAdd = '';
            switch ($parts[1][0]) {
                case '@':
                    $path = 'branches';
                    $url = 'tree';
                    break;
                case '/':
                    $masterBranch = Workflow::requestGithubApi('/repos/' . $parts[0], 'master_branch') ?: 'master';
                    $branches = Workflow::requestGithubApi('https://github.com/command_bar/' . $parts[0] . '/branches', 'results');
                    foreach ($branches as $branch) {
                        if ($branch->display === $masterBranch) {
                            $pathAdd = $masterBranch . '?q=' . substr($parts[1], 1) . '&sha=' . $branch->description;
                            break;
                        }
                    }
                    $path = 'paths/';
                    $url = 'blob/' . $masterBranch;
                    break;
                case '#':
                    $path = 'issues';
                    $url = 'issues';
                    if (isset($parts[1][1])) {
                        $pathAdd = '_for?q=' . substr($parts[1], 1);
                        $compareDescription = 0 === intval($parts[1][1]);
                    }
                    break;
            }
            $subs = Workflow::requestGithubApi('https://github.com/command_bar/' . $parts[0] . '/' . $path . $pathAdd, 'results');
            foreach ($subs as $sub) {
                if (0 === strpos($sub->command, $parts[0] . ' ' . $parts[1][0])) {
                    $endPart = substr($sub->command, strlen($parts[0] . ' ' . $parts[1][0]));
                    Workflow::addItem(Item::create()
                        ->title($sub->command)
                        ->comparator($parts[0] . ' ' . $parts[1][0] . ($compareDescription ? $sub->description : $endPart))
                        ->subtitle($sub->description)
                        ->arg('https://github.com/' . $parts[0] . '/' . $url . '/' . $endPart)
                        ->prio((isset($sub->multiplier) ? $sub->multiplier : 1))
                    );
                }
            }

        } else {

            $subs = array(
                'admin'   => 'Manage this repo',
                'graphs'  => 'All the graphs',
                'issues ' => 'List, show and create issues',
                'network' => 'See the network',
                'pulls'   => 'Show open pull requests',
                'pulse'   => 'See recent activity',
                'wiki'    => 'Pull up the wiki',
                'commits' => 'View commit history'
            );
            foreach ($subs as $key => $sub) {
                Workflow::addItem(Item::create()
                    ->title($parts[0] . ' ' . $key)
                    ->subtitle($sub)
                    ->arg('https://github.com/' . $parts[0] . '/' . $key)
                );
            }
            Workflow::addItem(Item::create()
                ->title($parts[0] . ' new issue')
                ->subtitle('Create new issue')
                ->arg('https://github.com/' . $parts[0] . '/issues/new?source=c')
            );
            Workflow::addItem(Item::create()
                ->title($parts[0] . ' new pull')
                ->subtitle('Create new pull request')
                ->arg('https://github.com/' . $parts[0] . '/pull/new?source=c')
            );
            Workflow::addItem(Item::create()
                ->title($parts[0] . ' milestones')
                ->subtitle('View milestones')
                ->arg('https://github.com/' . $parts[0] . '/issues/milestones')
            );
            if (false && empty($parts[1])) {
                $subs = array(
                    '#' => 'Show a specific issue by number',
                    '@' => 'Show a specific branch',
                    '/' => 'Show a blob'
                );
                foreach ($subs as $key => $subtitle) {
                    Workflow::addItem(Item::create()
                        ->title($parts[0] . ' ' . $key)
                        ->subtitle($subtitle)
                        ->arg($key . ' ' . $parts[0])
                        ->valid(false)
                    );
                }
            }
            Workflow::addItem(Item::create()
                ->title($parts[0] . ' clone')
                ->subtitle('Clone this repo')
                ->arg('https://github.com/' . $parts[0] . '.git')
            );

        }

    } elseif (!$isUser && !$isMy) {

        if ($isRepo) {
            $urls = array('/users/' . $queryUser . '/repos', '/orgs/' . $queryUser . '/repos');
        } else {
            $urls = array();
            foreach (Workflow::requestGithubApi('/user/orgs') as $org) {
                $urls[] = '/orgs/' . $org->login . '/repos';
            }
            array_push($urls, '/user/starred', '/user/subscriptions', '/user/repos');
        }
        $repos = array();
        foreach ($urls as $prio => $url) {
            $urlRepos = Workflow::requestGithubApi($url);
            foreach ($urlRepos as $repo) {
                $repo->prio = $prio;
                $repos[$repo->id] = $repo;
            }
        }
        foreach ($repos as $repo) {
            Workflow::addItem(Item::create()
                ->title($repo->full_name . ' ')
                ->subtitle($repo->description)
                ->arg('https://github.com/' . $repo->full_name)
                ->prio(30 + $repo->prio)
            );
        }

    }

    if ($isUser && isset($parts[1])) {
        $subs = array(
            'contributions' => array($queryUser, "View $queryUser's contributions"),
            'repositories'  => array($queryUser . '?tab=repositories', "View $queryUser's repositories"),
            'activity'      => array($queryUser . '?tab=activity', "View $queryUser's public activity"),
            'stars'         => array('stars/' . $queryUser, "View $queryUser's stars")
        );
        $prio = count($subs);
        foreach ($subs as $key => $sub) {
            Workflow::addItem(Item::create()
                ->prefix('@', false)
                ->title($queryUser . ' ' . $key)
                ->subtitle($sub[1])
                ->arg('https://github.com/' . $sub[0])
                ->prio($prio--)
            );
        }
    } elseif (!$isMy) {
        if (!$isRepo) {
            $users = Workflow::requestGithubApi('/user/following');
            foreach ($users as $user) {
                Workflow::addItem(Item::create()
                    ->prefix('@', false)
                    ->title($user->login . ' ')
                    ->subtitle($user->type)
                    ->arg($user->html_url)
                    ->prio(20)
                );
            }
        }
        Workflow::addItem(Item::create()
            ->title('my ')
            ->subtitle('Dashboard, settings, and more')
            ->prio(10)
            ->valid(false)
        );
    } else {
        $myPages = array(
            'dashboard'     => array('', 'View your dashboard'),
            'pulls'         => array('dashboard/pulls', 'View your pull requests'),
            'issues'        => array('dashboard/issues', 'View your issues'),
            'stars'         => array('stars', 'View your starred repositories'),
            'profile'       => array($userData->login, 'View your public user profile'),
            'settings'      => array('settings', 'View or edit your account settings'),
            'notifications' => array('notifications', 'View all your notifications')
        );
        foreach ($myPages as $key => $my) {
            Workflow::addItem(Item::create()
                ->title('my ' . $key)
                ->subtitle($my[1])
                ->arg('https://github.com/' . $my[0])
                ->prio(1)
            );
        }
    }

    Workflow::sortItems();

    if ($query) {
        $path = $isUser ? $queryUser : 'search?q=' . urlencode($query);
        Workflow::addItem(Item::create()
            ->title("Search GitHub for '$query'")
            ->arg('https://github.com/' . $path)
            ->autocomplete(false)
        , false);
    }

} else {

    $cmds = array(
        'delete cache' => 'Delete GitHub Cache (only for this Alfred Workflow)',
        'update' => 'Update this Alfred workflow'
    );
    if (Workflow::getConfig('autoupdate', true)) {
        $cmds['deactivate autoupdate'] = 'Deactivate auto updating this Alfred Workflow';
    } else {
        $cmds['activate autoupdate'] = 'Activate auto updating this Alfred Workflow';
    }
    foreach ($cmds as $cmd => $desc) {
        Workflow::addItem(Item::create()
            ->prefix('gh ')
            ->title('> ' . $cmd)
            ->subtitle($desc)
            ->arg('> ' . str_replace(' ', '-', $cmd))
        );
    }

    Workflow::sortItems();

}

print Workflow::getItemsAsXml();
