<?php

namespace App\Controllers;

use App\Models\CandidateVote;
use App\Models\CongressCandidate;
use App\Models\CongressMember;
use App\Models\Country;
use App\Models\CountryFunds;
use App\Models\CountryRelation;
use App\Models\LawProposal;
use App\Models\LawVote;
use App\Models\Money;
use App\Models\Region;
use App\Models\RegionConnection;
use App\Models\Tax;
use App\System\App;
use App\System\AppException;
use App\System\Controller;
use App\System\Input;

class Congress extends Controller
{
    const NATURAL_ENEMY = 1;
    const MUTUAL_PROTECTION_PACT = 2;
    const WORK_TAX = 3;
    const MANAGER_TAX = 4;
    const IMPEACHMENT = 5;
    const TRANSFER_FUNDS = 6;
    const CEASE_FIRE = 7;

    public $validLawTypes = [
        self::NATURAL_ENEMY,
        self::MUTUAL_PROTECTION_PACT,
        self::WORK_TAX,
        self::MANAGER_TAX,
        self::IMPEACHMENT,
        self::TRANSFER_FUNDS,
        self::CEASE_FIRE
    ];

    private $ownCountry = null;

    private function getOwnCountry ()
    {
        if (empty($this->ownCountry)) {
            $this->ownCountry = App::user()->getPoliticalParty()->country;
        }

        return $this->ownCountry;
    }

    public function declareWar ()
    {
        $this->mustBePresident();

        $ownCountry = $this->getOwnCountry();
        $regionId = Input::getInteger("region");

        if ($regionId < 1) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $region = Region::getFullInfo($regionId);

        // is he declaring the war to himself?
        if (!$region || $region->country->id == $ownCountry) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $regions = Region::where([
            "country" => $ownCountry
        ])->get();
        $ownRegions = [];

        foreach ($regions as $region) {
            $ownRegions[] = $region->id;
        }

        $hasConnection = RegionConnection::where([
            "region_a" => $region->id,
            "region_b" , "in", $ownRegions
        ])->first();

        if (!$hasConnection) {
            throw new AppException(AppException::INVALID_DATA);
        }

        // @ToDo finish this
    }

    public function mustBePresident () {
        if (!$this->isPresident()) {
            throw new AppException(AppException::ACCESS_DENIED);
        }
    }

    public function isPresident ()
    {
        $country = Country::find($this->getOwnCountry());

        return ($country->president == App::user()->getUid());
    }

    public function submitApplication ()
    {
        // @ToDo: check if his party congress representation is full

        $party = App::user()->getPoliticalParty();

        if (!$party) {
            throw new AppException(AppException::ACCESS_DENIED);
        }

        $success = CongressCandidate::create([
            "uid" => App::user()->getUid(),
            "country" => $party->country,
        ]);

        if ($success) {
            return $success["id"];
        }

        return false;
    }

    public function voteCandidate ()
    {
        $id = Input::getInteger("id");
        $inFavor = Input::getBoolean("vote");
        $myUid = App::user()->getUid();

        if ($id < 1) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $candidacy = CongressCandidate::find($id)->first();

        if (!$candidacy) {
            throw new AppException(AppException::INVALID_DATA);
        }

        // @ToDo: check if user can vote

        $candidateVoteQuery = [
            "candidate" => $candidacy->uid,
            "uid" => $myUid
        ];

        $hasAlreadyVoted = CandidateVote::where($candidateVoteQuery)->first();

        if ($hasAlreadyVoted) {
            throw new AppException(AppException::INVALID_DATA);
        }

        if ($inFavor) {
            $candidacy->yes++;
        } else {
            $candidacy->no++;
        }

        $saved = CandidateVote::create($candidateVoteQuery);

        if (!$saved) {
            throw new AppException(AppException::INVALID_DATA);
        }

        return ($candidacy->save() == true);
    }

    public function voteLaw ()
    {
        $law = Input::getInteger("law");
        $inFavor = Input::getBoolean("vote");

        if ($law < 1) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $lawProposal = LawProposal::where([
            "id" => $law
        ])->first();

        // prevent him from voting other country's laws
        if (!$lawProposal || $lawProposal->country != $this->getOwnCountry()) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $lawVoteQuery = [
            "law" => $law,
            "uid" => App::user()->getUid()
        ];

        $hasAlreadyVoted = LawVote::where($lawVoteQuery)->first();

        if ($hasAlreadyVoted) {
            throw new AppException(AppException::INVALID_DATA);
        }

        if ($inFavor) {
            $lawProposal->yes++;
        } else {
            $lawProposal->no++;
        }

        $lawVoteQuery["in_favor"] = $inFavor;
        $saved = LawVote::create($lawVoteQuery);

        if (!$saved) {
            throw new AppException(AppException::INVALID_DATA);
        }

        // check if votation has finished
        if (($lawProposal->yes + $lawProposal->no) == $lawProposal->expected_votes) {
            $lawProposal->finished = true;
        }

        if ($lawProposal->save()) {
            $this->applyLaw($lawProposal->id);

            return true;
        }

        return false;
    }

    public function resign ()
    {
        CongressMember::where([
            "uid" => App::user()->getUid()
        ])->delete();

        return true;
    }

    public function proposeLaw ()
    {
        $type = Input::getInteger("type");
        $reason = Input::getString("reason", true);
        $country = Input::getInteger("country");
        $amount = Input::getFloat("amount");
        $currency = strtolower(Input::getString("currency"));
        $member = Input::getInteger("uid");
        $uid = App::user()->getUid();
        $lawsCountry = $this->getOwnCountry();

        if (!in_array($type, $this->validLawTypes) || empty($reason)) {
            throw new AppException(AppException::INVALID_DATA);
        }

        // check the required vars for each law type
        switch ($type)
        {
            case self::NATURAL_ENEMY:
            case self::MUTUAL_PROTECTION_PACT:
                if ($country < 1) {
                    throw new AppException(AppException::INVALID_DATA);
                }

                if ($type == self::NATURAL_ENEMY) {
                    $isAlly = CountryRelation::where([
                        "country" => $lawsCountry,
                        "target" => $country,
                        "relation" => "ally"
                    ])->first();

                    if ($isAlly) {
                        throw new AppException(AppException::INVALID_DATA);
                    }
                } else {
                    $isEnemy = CountryRelation::where([
                        "country" => $lawsCountry,
                        "target" => $country,
                        "relation" => "enemy"
                    ])->first();

                    if ($isEnemy) {
                        throw new AppException(AppException::INVALID_DATA);
                    }
                }
                break;
            case self::WORK_TAX:
            case self::MANAGER_TAX:
                if ($amount < 0) {
                    throw new AppException(AppException::INVALID_DATA);
                }
                break;
            case self::IMPEACHMENT:
                if ($uid < 1) {
                    throw new AppException(AppException::INVALID_DATA);
                }

                $isCongressist = CongressMember::where([
                    "country" => $this->getOwnCountry(),
                    "uid" => $uid
                ])->first();

                if (!$isCongressist) {
                    throw new AppException(AppException::INVALID_DATA);
                }
                break;
            case self::TRANSFER_FUNDS:
                if ($uid < 1 || empty($currency) || $amount < 0) {
                    throw new AppException(AppException::INVALID_DATA);
                }

                $countryFunds = CountryFunds::find($country);

                if ($countryFunds[$currency] < $amount) {
                    throw new AppException(AppException::INVALID_DATA);
                }
                break;
        }

        // check the last time that he proposed a law (to prevent spam)
        $lastProposal = LawProposal::where([
            "uid" => $uid,
            "created_at" => ">= " . strtotime("-2 days")
        ])->first();

        if ($lastProposal) {
            throw new AppException(AppException::INVALID_DATA);
        }

        $created = LawProposal::create([
            "uid" => $uid,
            "country" => $lawsCountry,
            "reason" => $reason,
            "target_country" => $country,
            "member" => $member,
            "amount" => $amount,
            "currency" => $currency,
            "expected_votes" => CongressMember::where(["country" => $lawsCountry])->get()->count(),
            "finished" => false
        ]);

        return ($created == true);
    }

    public function applyLaw ($id)
    {
        $lawProposal = LawProposal::where([
            "id" => $id
        ])->first();

        if (!$lawProposal) {
            throw new AppException(AppException::INVALID_DATA);
        }

        switch($lawProposal->type)
        {
            case self::WORK_TAX:
            case self::MANAGER_TAX:
                $tax = Tax::where([
                    "country" => $lawProposal->country,
                    "type" => $lawProposal->type,
                ])->first();

                $tax->amount = $lawProposal->amount;
                break;
            case self::CEASE_FIRE:
                CountryRelation::where([
                    "country" => $lawProposal->country,
                    "target" => $lawProposal->target_country,
                    "relation" => "enemy"
                ])->delete();
                break;
            case self::NATURAL_ENEMY:
                CountryRelation::create([
                    "country" => $lawProposal->country,
                    "target" => $lawProposal->target_country,
                    "relation" => "enemy"
                ]);
                break;
            case self::MUTUAL_PROTECTION_PACT:
                CountryRelation::create([
                    "country" => $lawProposal->country,
                    "target" => $lawProposal->target_country,
                    "relation" => "ally"
                ]);
                break;
            case self::IMPEACHMENT:
                CongressMember::where([
                    "uid" => $lawProposal->member
                ])->delete();
                break;
            case self::TRANSFER_FUNDS:
                $countryFunds = CountryFunds::find($lawProposal->country);

                // detect fraud attempts
                // they vote to transfer money when is available in the bank
                // and once the votation has started they decrease the funds in the bank,
                // so without this check, money could  be magically created (like USA does in real world)
                if ($countryFunds[$lawProposal->currency] < $lawProposal->amount)
                {
                    // edit the votes to set the votation as failed
                    $lawProposal->yes = 0;
                    $lawProposal->no = $lawProposal->expected_votes;
                    $lawProposal->reason = "Fraud attempt";
                    $lawProposal->save();

                    throw new AppException(AppException::INVALID_DATA);
                }

                $countryFunds[$lawProposal->currency] -= $lawProposal->amount;
                $countryFunds->save();

                $userMoney = Money::find($lawProposal->member)->first();
                $userMoney[$lawProposal->currency] += $lawProposal->amount;
                $userMoney->save();
                break;
        }
    }
}