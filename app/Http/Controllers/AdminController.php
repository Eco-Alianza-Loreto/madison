<?php

namespace App\Http\Controllers;

use App\Config\Models\Config as ConfigModel;
use App\Events\SponsorStatusChanged;
use App\Http\Requests\Admin as Requests;
use App\Models\Doc as Document;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Sponsor;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Mail;
use SiteConfigSaver;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function usersIndex(Requests\Users\Index $request)
    {
        $usersQuery = User::query();

        if ($request->has('q')) {
            $usersQuery->search($request->get('q'));
        }

        $limit = $request->input('limit', 12);
        $page = $request->input('page', 1);
        $orderField = $request->input('order', 'updated_at');
        $orderDir = $request->input('order_dir', 'DESC');

        if ($request->has('q')
            && (!$request->has('order') || $orderField === 'relevance')
        ) {
            $usersQuery->orderByRelevance();
        } elseif ($orderField === 'relevance' && !$request->has('q')) {
            // relevance ordering only makes sense with a query
            flash(trans('messages.relevance_ordering_warning'));
            $usersQuery->orderBy('updated_at', 'desc');
        } else {
            $usersQuery->orderBy($orderField, $orderDir);
        }

        $orderedUsers = $usersQuery->get();
        $totalCount = $orderedUsers->count();
        $orderedAndLimitedUsers = $orderedUsers->forPage($page, $limit);

        $users = new LengthAwarePaginator(
            $orderedAndLimitedUsers,
            $totalCount, // total items possible
            $limit,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page'
            ]
        );

        return view('admin.manage-users', compact('users'));
    }

    public function sponsorsIndex(Requests\Sponsors\Index $request)
    {
        $sponsorsQuery = Sponsor::query();

        if ($request->has('q')) {
            $sponsorsQuery->search($request->get('q'));
        }

        if ($request->has('status')) {
            $sponsorsQuery->where('status', $request->input('status'));
        }

        $limit = $request->input('limit', 12);
        $page = $request->input('page', 1);
        $orderField = $request->input('order', 'updated_at');
        $orderDir = $request->input('order_dir', 'DESC');

        if ($request->has('q')
            && (!$request->has('order') || $orderField === 'relevance')
        ) {
            $sponsorsQuery->orderByRelevance();
        } elseif ($orderField === 'relevance' && !$request->has('q')) {
            // relevance ordering only makes sense with a query
            flash(trans('messages.relevance_ordering_warning'));
            $sponsorsQuery->orderBy('updated_at', 'desc');
        } else {
            $sponsorsQuery->orderBy($orderField, $orderDir);
        }

        $orderedSponsors = $sponsorsQuery->get();
        $totalCount = $orderedSponsors->count();
        $orderedAndLimitedSponsors = $orderedSponsors->forPage($page, $limit);

        $sponsors = new LengthAwarePaginator(
            $orderedAndLimitedSponsors,
            $totalCount, // total items possible
            $limit,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page'
            ]
        );

        $statuses = collect(['' => trans('messages.sponsor.statuses.all')]);
        collect(Sponsor::getStatuses())
            ->each(function ($status) use ($statuses) {
                $statuses->put($status, trans('messages.sponsor.statuses.'.$status));
            })
            ;
        return view('admin.manage-sponsors', compact('sponsors', 'statuses'));
    }

    public function sponsorsPutStatus(Requests\Sponsors\PutStatus $request, Sponsor $sponsor)
    {
        $oldStatus = $sponsor->status;
        $newStatus = $request->input('status');

        if ($oldStatus !== $newStatus) {
            $sponsor->status = $newStatus;
            $sponsor->save();

            event(new SponsorStatusChanged($oldStatus, $newStatus, $sponsor, $request->user()));

            flash(trans('messages.sponsor.status_updated'));
        }

        return redirect()->route('admin.sponsors.index');
    }

    public function usersPostAdmin(Requests\Users\PostAdmin $request, User $user)
    {
        $makingAdmin = $request->input('admin', false);

        if ($makingAdmin) {
            $user->makeAdmin();
        } else {
            $user->removeAdmin();
        }

        flash(trans('messages.updated'));
        return redirect()->route('admin.users.index');
    }

    /**
     * Admin page for configuring site settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function siteSettingsIndex(Requests\SiteSettings\Index $request)
    {
        $dbSettings = collect(SiteConfigSaver::get());

        // reset config() to just the configuration file contents so we can
        // show correct default values
        (new \Illuminate\Foundation\Bootstrap\LoadConfiguration())
            ->bootstrap(app());

        $allSettingsDesc = static::getSiteSettingKeys();
        $currentSettings = new \stdClass();
        $options = [];
        foreach ($allSettingsDesc as $key => $desc) {
            $k = str_replace('.', '_', $key);

            if ($val = $dbSettings->get($key)) {
                $currentSettings->{$k} = $val;
            } else {
                // not set in the database
                switch ($desc['type']) {
                    case 'select':
                        $currentSettings->{$k} = 'default';
                        break;
                    case 'text':
                        $currentSettings->{$k} = null;
                        break;
                }
            }

            switch ($desc['type']) {
                case 'select':
                    $options[$key] = [
                        'choices' => static::addDefaultOption(
                            $desc['choices'],
                            config($key)
                        )
                    ];
                    break;
                case 'text':
                    $options[$key] = [
                        'placeholder' => static::makeDefaultString(config($key)),
                    ];
                    break;
            }
        }

        // We have to put the key into the desc properties since `groupBy` gets rid of them
        $groupedSettingsDesc = collect($allSettingsDesc)->map(function ($setting, $key) {
            $setting['key'] = $key;
            return $setting;
        })->groupBy('group');

        // reset config() to full settings
        (new \App\Config\Bootstrap\LoadConfiguration())
            ->bootstrap(app());

        return view('admin.site-settings', compact([
            'groupedSettingsDesc',
            'currentSettings',
            'options',
        ]));
    }

    public function siteSettingsUpdate(Requests\SiteSettings\Update $request)
    {
        foreach (static::getSiteSettingKeys() as $key => $desc) {
            $input = $request->input(str_replace('.', '_', $key));

            list($group, $item) = ConfigModel::explodeGroupAndKey($key);
            $existingModel = ConfigModel
                ::where('group', $group)
                ->where('key', $item);

            if ((!$input || $input === 'default') && $existingModel) {
                $existingModel->delete();
                SiteConfigSaver::refresh();
            } else {
                SiteConfigSaver::set($key, $input);
            }
        }

        flash(trans('messages.updated'));
        return redirect()->route('admin.site.index');
    }

    /**
     * Show a list of featured documents so an admin can manage them.
     *
     * @return \Illuminate\Http\Response
     */
    public function indexFeaturedDocuments(Requests\FeaturedDocuments\Index $request)
    {
        $documents = Document::getFeatured(false);
        $nonFeaturedDocuments = Document
            ::whereNotIn('id', Document::getFeaturedDocumentIds())
            ->where('is_template', '!=', '1')
            ->latest()
            ->get()
            ;
        return view('admin.featured-documents', compact([
            'documents',
            'nonFeaturedDocuments',
        ]));
    }

    /**
     * Updates the position of a featured document.
     *
     * @return \Illuminate\Http\Response
     */
    public function updateFeaturedDocuments(Requests\FeaturedDocuments\Update $request, Document $document)
    {
        $action = $request->input('action');

        $featuredSetting = Setting::where('meta_key', '=', 'featured-doc')->first();
        $featuredIds = explode(',', $featuredSetting->meta_value);

        $currentPos = array_search((string) $document->id, $featuredIds);

        if ($currentPos === false) {
            throw new \Exception('Invalid Document ID');
        }

        if ($currentPos === 0 && $action === 'up' || $currentPos === count($featuredIds) - 1 && $action === 'down') {
            throw new \Exception('Invalid move');
        }

        # Create a copy of the original array, to avoid errors from reference
        # assignments as opposed to value assignments.
        $idReferences = array_flip(array_flip($featuredIds));

        if ($action === "up") {
            $featuredIds[$currentPos] = $idReferences[$currentPos - 1];
            $featuredIds[$currentPos - 1] = (string) $document->id;
        } else if ($action === "down") {
            $featuredIds[$currentPos] = $idReferences[$currentPos + 1];
            $featuredIds[$currentPos + 1] = (string) $document->id;
        } else if ($action === "remove") {
            unset($featuredIds[$currentPos]);
        }

        $featuredSetting->meta_value = join(',', $featuredIds);
        $featuredSetting->save();

        flash(trans('messages.admin.updated_featured_documents'));
        return redirect()->route('admin.featured-documents.index');
    }

    public function addFeaturedDocument(Requests\FeaturedDocuments\Index $request)
    {
        $document = Document::findOrFail($request->input('add_featured_doc_id'));

        $document->setAsFeatured();

        flash(trans('messages.admin.updated_featured_documents'));
        return redirect()->route('admin.featured-documents.index');
    }

    public static function addDefaultOption($choices, $current)
    {
        $value = '';
        if (!isset($choices[$current])) {
            $value = 'Unknown';
        } else {
            $value = static::makeDefaultString($choices[$current]);
        }
        return ['default' => $value]+$choices;
    }

    public static function makeDefaultString($choice)
    {
        return 'Default ('.($choice ?: trans('messages.none')).')';
    }

    public static function validDateFormats()
    {
        return [
            'Y-m-d' => 'ISO 8601: 2009-06-27',
            'n/j/Y' => 'US: 06/27/2009',
            'd-m-Y' => 'Europe: 27-06-2009',
        ];
    }

    public static function validTimeFormats()
    {
        return [
            'g:i A' => '12 Hour, 1:15 PM',
            'H:i' => '24 Hour, 13:15',
        ];
    }

    public static function getSiteSettingKeys()
    {
        return [
            'madison.date_format' => [
                'group' => 'date_time',
                'type' => 'select',
                'choices' => static::validDateFormats(),
            ],
            'madison.time_format' => [
                'group' => 'date_time',
                'type' => 'select',
                'choices' => static::validTimeFormats(),
            ],
            'madison.google_analytics_property_id' => [
                'group' => 'google_analytics',
                'type' => 'text',
            ],
        ];
    }
}
