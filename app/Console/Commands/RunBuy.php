<?php
/**
 * Created by PhpStorm.
 * User: curtiscrewe
 * Date: 19/10/2018
 * Time: 03:49
 */

namespace App\Console\Commands;

use App\Events\CardPurchase;
use App\Events\CardSold;
use App\Models\Accounts;
use App\Models\Players;
use App\Models\Transactions;
use App\Traits\Action;
use Carbon\Carbon;
use FUTApi\Core;
use FUTApi\FutError;
use Illuminate\Console\Command;
use Backpack\Settings\app\Models\Setting;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputOption;

class RunBuy extends Command {

    use Action;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'buy:cron {--debug}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the autobuyer';

    /**
     * The FUT API object
     *
     * @var array
     */
    protected $fut;

    /**
     * The Account Object
     *
     * @var \App\Models\Accounts
     */
    protected $account = [];

    /**
     * Current FUT auction
     *
     * @var array
     */
    protected $status = [];

    /**
     * Requests Performed
     */
    protected $requests = 0;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct() {
        parent::__construct();
    }


    public function handle() {
        if ($this->option('debug') == false && Setting::get('autobuyer_status') == '0') {
            $this->info("Autobuyer disabled.");
            return response(['status' => 403]);
        }
        if (Setting::get('account_mode') == '1') {
            abort(403);
        }
        //remove stuck in-use accounts
        Accounts::query()->where('updated_at', '>=', Carbon::now()->subMinutes(15)->toDateTimeString())->where('in_use', '1')->update([
            'in_use' => '0'
        ]);
        $this->account = Accounts::where('status', '1')->where('in_use', '0')->whereNotNull('phishingToken')->first();
        if(!$this->account) {
            abort(403);
        }
        $this->account->update([
            'in_use' => ($this->option('debug') == false ? '1' : '0')
        ]);
        try {

            $this->fut = new Core(
                $this->account->email,
                $this->account->password,
                strtolower($this->account->platform),
                null,
                false,
                $this->option('debug'),
                storage_path(
                    'app/fut_cookies/' . md5($this->account->email)
                )
            );
            $this->fut->setSession(
                $this->account->personaId,
                $this->account->nucleusId,
                $this->account->phishingToken,
                $this->account->sessionId,
                date("Y-m", strtotime($this->account->dob))
            );
            if($this->account->cooldown == 1) {
                if(Carbon::now()->diffInMinutes(Carbon::parse($this->account->cooldown_activated)) > Setting::get('account_cooldown')) {
                    $this->account->update([
                        'phishingToken' => null,
                        'sessionId' => null,
                        'nucleusId' => null,
                        'cooldown' => '0',
                        'in_use' => '0'
                    ]);
                    $this->log('Account #' . $this->account->id . ' has been removed from cooldown!');
                    abort(200);
                } else {
                    $this->account->update([
                        'in_use' => '0'
                    ]);
                    abort(403);
                }
            } else {
                if(Carbon::now()->diffInMinutes(Carbon::parse($this->account->last_login)) > Setting::get('account_runtime')) {
                    $this->account->update([
                        'cooldown' => '1',
                        'in_use' => '0',
                        'cooldown_activated' => new Carbon
                    ]);
                    $this->fut->logout();
                    $this->log('Account #' . $this->account->id . ' has been placed in cooldown!');
                    abort(403);
                }
            }
            $credits = $this->fut->keepalive();
            if($credits !== false) {
                $this->account->update([
                    'coins' => $credits
                ]);
            }

            $this->sort_item_list();

            switch($this->account->platform) {
                case "XBOX":
                    $players = Players::query()->where('xb_buy_bin', '<', $credits)->where('xb_buy_bin', '!=', '0')->where('status', '1')->orderBy('last_searched', 'ASC')->get();
                    break;
                case "PS4":
                    $players = Players::query()->where('ps_buy_bin', '<', $credits)->where('ps_buy_bin', '!=', '0')->where('status', '1')->orderBy('last_searched', 'ASC')->get();
                    break;
                case "PC":
                    $players = Players::query()->where('pc_buy_bin', '<', $credits)->where('pc_buy_bin', '!=', '0')->where('status', '1')->orderBy('last_searched', 'ASC')->get();
                    break;
            }

            if($players->count() === 0) {
                $this->account->update([
                    'in_use' => '0'
                ]);
                abort(403);
            }

            $original_rpm_limit = Setting::get('rpm_limit');

            //rpm prevention
            if($this->requests >= $original_rpm_limit) {
                $this->account->update([
                    'in_use' => '0'
                ]);
                abort(403);
            }

            $bid_limit = Setting::get('player_limit');
            $rpm_limit = $original_rpm_limit/$players->count();
            $times = [];
            $time = 0;
            $sum_to = 55;
            while(array_sum($times) != $sum_to) {
                $times[$time] = mt_rand(1, $sum_to / mt_rand(1, 5));
                if (++$time == $original_rpm_limit) {
                    $time = 0;
                }
            }

            foreach($players as $player) {
                switch($this->account->platform) {
                    case "XBOX":
                        $sell_bin = $player->xb_sell_bin;
                        $buy_bin = $player->xb_buy_bin;
                        break;
                    case "PS4":
                        $sell_bin = $player->ps_sell_bin;
                        $buy_bin = $player->ps_buy_bin;
                        break;
                    case "PC":
                        $sell_bin = $player->pc_sell_bin;
                        $buy_bin = $player->pc_buy_bin;
                        break;
                }
                switch($player->card_type) {
                    case "player":
                        $name = $player->name;
                        break;
                    case "position":
                        $name = $player->position_rel->position_name;
                        break;
                    case "chemistry":
                        $name = $player->chemistry_rel->playStyle_name;
                        break;
					case "fitness":
						$name = $player->fitness_rel->fitness_name;
                        break;
					
                }
                Log::info('We are going to search for '.$name.' with a max BIN of '.$buy_bin);
                $cards_selling = Transactions::query()->where('player_id', $player->id)->where('account_id', $this->account->id)->whereNull('sell_bin')->get();
                if($cards_selling->count() >= $bid_limit) {
                    continue;
                }
                if($this->account->tradepile_cards < $this->account->tradepile_limit){
                    if($this->account->coins > $buy_bin) {
                        if($buy_bin > 600){
                            $sleep_time = array_pop($times);
                            $counter = 0;
                            $bids = 0;
                            $auctions = 0;
                            $auctionsWon = 0;
                            $auctionsFailed = 0;
                            do {
                                if($bids >= $bid_limit) {
                                    break;
                                }
                                sleep($sleep_time);
                                $randomBid = rand(14000000, 15000000);
                                $formattedBid = floor($randomBid / 1000) * 1000;

                                switch($player->card_type) {
                                    case "player":
                                        $search = $this->fut->searchAuctions(
                                            'player',
                                            null,
                                            null,
                                            $player->resource_id,
                                            null,
                                            null,
                                            $formattedBid,
                                            null,
                                            $buy_bin,
                                            null,
                                            null,
                                            null,
                                            null,
                                            null,
                                            null,
                                            null,
                                            0,
                                            21
                                        );
                                        $search['auctionInfo'] = collect($search['auctionInfo'])->filter(function($auction) {
                                            return $auction['itemData']['injuryType'] == 'none' && $auction['itemData']['contract'] > 0;
                                        })->sortBy('buyNowPrice')->values()->toArray();
                                        break;
                                    case "position":
                                        $search = $this->fut->searchAuctions(
                                            'training',
                                            null,
                                            'position',
                                            null,
                                            null,
                                            null,
                                            $formattedBid,
                                            null,
                                            $buy_bin,
                                            null,
                                            null,
                                            $player->position_rel->position_id,
                                            null,
                                            null,
                                            null,
                                            null,
                                            0,
                                            21
                                        );
                                        $search['auctionInfo'] = collect($search['auctionInfo'])->sortBy('buyNowPrice')->values()->toArray();
                                        break;
                                    case "chemistry":
                                        $search = $this->fut->searchAuctions(
                                            'training',
                                            null,
                                            'playStyle',
                                            null,
                                            null,
                                            null,
                                            $formattedBid,
                                            null,
                                            $buy_bin,
                                            null,
                                            null,
                                            null,
                                            null,
                                            null,
                                            null,
                                            $player->chemistry_rel->playStyle_id,
                                            0,
                                            21
                                        );
                                        $search['auctionInfo'] = collect($search['auctionInfo'])->sortBy('buyNowPrice')->values()->toArray();
                                        break;
									case "fitness":
										$search = $this->fut->searchAuctions(
											'development',
											null,
											'fitness',
											null,
                                            5002006,
                                            null,
                                            $formattedBid,
                                            null,
                                            $buy_bin,
                                            null,
                                            null,
                                            null,
                                            null,
                                            null,
                                            null,
                                            null,
                                            0,
                                            21
										);
										 $search['auctionInfo'] = collect($search['auctionInfo'])->sortBy('buyNowPrice')->values()->toArray();
                                        break;
											
                                }

                                if(!empty($search['auctionInfo'])) {
                                    if(count($search['auctionInfo']) > 4) {
                                        $this->log('Too many auctions for '.$name.', verify whether price is correct!');
                                        $counter++;
                                        continue;
                                    }
                                    foreach($search['auctionInfo'] as $auction) {
                                        $bids++;
                                        $auctions++;
                                        try {
                                            $bid = $this->fut->bid($auction['tradeId'], $auction['buyNowPrice']);
                                            if(isset($bid['auctionInfo'])) {
                                                $auctionsWon++;
                                                $transaction = Transactions::insertGetId([
                                                    'player_id' => $player->id,
                                                    'card_id' => $auction['itemData']['id'],
                                                    'buy_bin' => $auction['buyNowPrice'],
                                                    'account_id' => $this->account->id,
                                                    'platform' => $this->account->platform,
                                                    'bought_time' => new Carbon
                                                ]);
                                                $this->log('An auction was just won for '.$name.' & was bought at '.$auction['buyNowPrice'].' with a potential profit of '.number_format(round(($sell_bin *0.95) - $auction['buyNowPrice'])));
                                                event(new CardPurchase(Transactions::find($transaction)));
                                            }
                                        } catch (FutError $e) {
                                            $error = $e->GetOptions();
                                            $this->log('We failed to buy the auction for '.$name.' after attempting to buy him for '.$auction['buyNowPrice']);
                                            $auctionsFailed++;
                                        }
                                    }
                                }
                                $counter++;
                            } while($counter < $rpm_limit);
                            //update the player
                            if(isset($counter) && isset($auctions) && isset($auctionsWon) && isset($auctionsFailed)) {
                                Log::info('Search completed for '.$name.' with '.$counter.' total searches, '.$auctions.' auctions, winning '.$auctionsWon.' & losing '.$auctionsFailed);
                                $player->increment('total_searches', $counter);
                                $player->increment('auctions_found', $auctions);
                                $player->increment('auctions_won', $auctionsWon);
                                $player->increment('auctions_failed', $auctionsFailed);
                                $player->save();
                            }
                        }
                    }
                }
            }

        } catch(FutError $exception) {

			throw $exception;
		
            $error = $exception->GetOptions();
			
            if($error['reason'] !== 'permission_denied') {
                $this->account->update([
                    'phishingToken' => null,
                    'sessionId' => null,
                    'nucleusId' => null,
                    'status' => '-1',
                    'status_reason' => $error['reason'],
                    'in_use' => '0'
                ]);
            }

            $this->log('Account #' . $this->account->id . ' has caught an exception! - ' . $error['reason']);

            abort(403);

        }

        $this->account->update([
            'in_use' => '0'
        ]);

        return ['status' => 200];
    }

    protected function handleUnassignedItems()
    {
        $items = $this->fut->unassigned();
        foreach($items['itemData'] as $item) {
            $this->storeAction(function () use($item) {
                $this->fut->sendToTradepile($item['id'], false);
            });
        }
        $this->performActions();
    }

    protected function handleTradePileItems()
    {
        $tradepile = $this->fut->tradepile();
        $tradepile_value = 0;
        if(count($tradepile['auctionInfo']) == 0) {
            $this->account->tradepile_cards = 0;
            $this->account->tradepile_value = 0;
            $this->account->save();
            return false;
        }
        foreach($tradepile['auctionInfo'] as $key => $auction) {
            /** @var \App\Models\Transactions $trade */
            $trade = Transactions::where('card_id', $auction['itemData']['id'])->first();
            if(!$trade) {
                continue;
            }
            /** @var \App\Models\Players $player */
            $player = $trade->player;
            if(!$player) {
                continue;
            }
            $platform = $this->account->platform;
            $sell_bid = $player->getSellBid($platform);
            $sell_bin = $player->getSellBin($platform);
            if($trade->sell_bin > 0) {
                $tradepile_value = $tradepile_value + ($trade->sell_bin * 0.95);
            } else {
                $tradepile_value = $tradepile_value + ($trade->listed_bin * 0.95);
            }
            if($auction['tradeState'] == NULL || $auction['tradeState'] == "expired"){
                //lets sell
                $this->storeAction(function() use($trade, $sell_bin, $sell_bid) {
                    $sale = $this->fut->sell($trade->card_id, $sell_bid, $sell_bin);
                    $trade->listed_bin = $sell_bin;
                    $trade->listed_time = new Carbon;
                    $trade->save();
                });
            } elseif($auction['tradeState'] == "closed"){
                //we sold it!
                $this->storeAction(function() use($auction, $trade, $player)  {
                    $this->fut->removeSold($auction['tradeId']);
                    $trade->sell_bin = $auction['currentBid'];
                    $trade->sold_time = new Carbon;
                    $trade->save();
                    event(new CardSold(Transactions::find($trade->id)));
                    $this->log($player->getName().' was sold at '.$auction['currentBid'].' with a profit of '.number_format(round(($auction['currentBid'] *0.95) - $trade->buy_bin)));
                });
            }
            $this->performActions();
        }
        $this->account->tradepile_cards = count($tradepile['auctionInfo']);
        $this->account->tradepile_value = $tradepile_value;
        $this->account->save();
    }


    private function sort_item_list()
    {
        try {
            $this->handleUnassignedItems();
            $this->handleTradePileItems();
        } catch(FutError $e) {
            $error = $e->GetOptions();
            $this->account->update([
                'phishingToken' => null,
                'sessionId' => null,
                'nucleusId' => null,
                'status' => '-1',
                'status_reason' => $error['reason'],
                'in_use' => '0'
            ]);
            $this->log('Account #' . $this->account->id . ' has caught an exception in the sort item list! - ' . $error['reason']);
            abort(403);
        }
    }

    /**
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['debug', null, InputOption::VALUE_OPTIONAL, true, null],
        ];
    }

    /**
     * @param $message
     */
    protected function log($message)
    {
        if(config('laravel-slack.slack_webhook_url') !== null) {
            \Slack::to(config('laravel-slack.default_channel'))->send($message);
        }

        Log::info($message);
    }

}