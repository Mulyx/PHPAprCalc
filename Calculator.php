<?php

    class Calculator
    {
        const MAXIMUM_ITERATIONS  = 100;
        const ACCURACY = 1.0e-6;
        const WITHDRAWAL_PERIOD = 14;
        const SETTLEMENT_DAYS = 13;
        const PERIOD_DAILY = 365;
        const PERIOD_WEEKLY = 52;
        const PERIOD_FORTNIGHTLY = 26;
        const PERIOD_MONTHLY = 12;

        protected $principal;
        protected $interest;
        protected $installments;
        protected $first = 0.00;
        protected $last = 0.00;
        protected $unit;
        protected $deferment;
        protected $outliers = 0;
        protected $periodPayment;
        protected $period = 1;
        protected $paid = 0;
        protected $deferred = 0;
        protected $payments;
        protected $irr;
        protected $settlements;

        public function __construct($principal, $interest, $installments, $daysSinceStart, $daysPaidUp, $firstPayment = 0.00, $lastPayment = 0.00, $unit = self::PERIOD_DAILY)
        {
            $this->principal = (float) $principal;
            $this->interest = (float) $interest;
            $this->installments = (integer) $installments;
            $this->first = (float) $firstPayment;
            $this->last = (float) $lastPayment;
            $this->unit = (integer) $unit;
            $this->deferment = round(365 / $this->unit);
            $this->outliers = count(array_filter(array($this->first, $this->last)));
            $this->periodPayment = ($this->principal + $this->interest - $this->first - $this->last) / (($this->installments - $this->outliers) ?: 1);
            $this->period = $daysSinceStart;
            $this->paid = $installments;
        }

        protected static function npv($rate, array $values)
        {
            for ($i = 0, $npv = 0.0; $i < count($values); $i++) {
                $npv += $values[$i] / pow(1 + $rate, $i + 1);
            }
            return (is_finite($npv) ? $npv: null);
        }

        protected static function irr(array $cashflow, $guess = 0.1)
        {
            $x = 0.0;
            $f1 = static::npv($x, $cashflow);
            $f2 = static::npv($guess, $cashflow);
            for ($i = 0; $i < static::MAXIMUM_ITERATIONS; $i++) {
                if (($f1 * $f2) < 0.0) {
                    break;
                }
                if (abs($f1) < abs($f2)) {
                    $f1 = static::npv($x += 1.6 * ($x - $guess), $cashflow);
                }
                else {
                    $f2 = static::npv($guess += 1.6 * ($guess - $x), $cashflow);
                }
            }
            if (($f1 * $f2) > 0.0) {
                return null;
            }
            $f = static::npv($x, $cashflow);
            if ($f < 0.0) {
                $rtb = $x;
                $dx = $guess - $x;
            }
            else {
                $rtb = $guess;
                $dx = $x - $guess;
            }
            for ($i = 0;  $i < static::MAXIMUM_ITERATIONS; $i++) {
                $dx *= 0.5;
                $x_mid = $rtb + $dx;
                $f_mid = static::npv($x_mid, $cashflow);
                if ($f_mid <= 0.0) {
                    $rtb = $x_mid;
                }
                if ((abs($f_mid) < static::ACCURACY) || (abs($dx) < static::ACCURACY)) {
                    return $x_mid;
                }
            }
            return null;
        }

        protected function getPayments()
        {
            if (!is_array($this->payments)) {
                $count = 1;
                $payments = array();
                $payments[] = ($this->installments - $this->outliers >= $count)
                    ? ($this->first > 0
                        ? $this->first
                        : $this->periodPayment)
                    : 0;
                for ($count++; $count <= $this->installments; $count++) {
                    $payments[] = ($this->last > 0 && $this->installments = $count)
                        ? $this->last
                        : $this->periodPayment;
                }
                $this->payments = $payments;
            }
            return $this->payments;
        }

        protected function getIRR()
        {
            if ($this->irr === null) {
                $cashflow = $this->getPayments();
                array_unshift($cashflow, $this->principal * -1);
                $this->irr = static::irr($cashflow, 0.01);
            }
            return $this->irr;
        }

        protected function getSettleAmounts()
        {
            if (!is_array($this->settlements)) {
                $settlements = array();
                $payments = $this->getPayments();
                for ($count = 0; $count < $this->installments; $count++) {
                    $last = end($settlements) ?: $this->principal;
                    $settlements[] = $last * (1 + $this->getIRR()) - $payments[$count];
                }
                $this->settlements = $settlements;
            }
            return $this->settlements;
        }

        public function getPeriodPayment()
        {
            return $this->periodPayment;
        }

        public function getRepresentativeAPR()
        {
            $irrArguments = $this->getPayments();
            array_unshift($irrArguments, $this->principal * -1);
            return pow(1 + $this->getIRR(), $this->unit) - 1;
        }

        public function getFlatRate()
        {
            return $this->interest / $this->principal;
        }

        public function getRatePerAnnum()
        {
            return $this->getFlatRate() / $this->installments / $this->deferment * 365;
        }

        public function getPeriodRate()
        {
            return $this->getRatePerAnnum() / $this->unit;
        }

        public function getInterestByDay()
        {
            return $this->interest / ($this->installments * $this->deferment);
        }

        public function getPeriodInterestAmount()
        {
            return $this->getInterestByDay() * $this->deferment;
        }

        public function getTotalInterestPayable()
        {
            return static::WITHDRAWAL_PERIOD * $this->getInterestByDay();
        }

        public function getAmountForSettlment()
        {
            $settlements = $this->getSettleAmounts();
            return $settlements[($this->getInstallmentsDue() ?: 1) - 1];
        }

        public function getSettlementCalc()
        {
            return $this->getInstallmentsDue() == $this->installments ? ((0 - $this->getSettlementQuarterFigure()) * $this->periodPayment()) + ($this->last > 0 ? $this->last - $this->periodPayment : 0) : $this->getAmountForSettlment() + $this->getArrearsAmount();
        }

        public function getSettlementProportion()
        {
            return $this->getAmountForSettlment() * $this->getIRR() / $this->deferment * static::SETTLEMENT_DAYS;
        }

        public function getInstallmentsDue()
        {
            $due = $this->period - $this->deferred;
            return $due < $this->installments ? $due : $this->installments;
        }

        public function getInstallmentsNotDue()
        {
            return $this->installments - $this->period + $this->deferred;
        }

        public function getInstallmentsInArrears()
        {
            return $this->installments - $this->paid - $this->getInstallmentsNotDue();
        }

        public function getArrearsAmount()
        {
            return $this->getInstallmentsInArrears() * $this->periodPayment;
        }

        protected function getSettlementQuarterFigure()
        {
            return $this->installments / 4 + $this->deferred;
        }

        public function getSettlementBalance()
        {
            return $this->getInstallmentsNotDue() * $this->getPeriodPayment() + ($this->last > 0 ? $this->last - $this->getPeriodPayment() : 0) + $this->getArrearsAmount();
        }

        public function getSettlementInfo()
        {
            return $this->getSettlementCalc() + $this->getSettlementProportion();
        }

        public function getSettlementCashback()
        {
            return $this->getSettlementBalance() - $this->getSettlementInfo();
        }

        protected function getRebateSettlementCalculation()
        {
            $Q6 = $this->period + $this->deferred;
            if ($this->period >= $this->installments)
                return 0;
            
            $settlements = $this->getSettleAmounts();
            array_unshift($settlements, null);
            unset($settlements[0]);

            if (!isset($settlements[$Q6]))
                return $this->principal;

            return $this->period + $this->deferred >= $this->installments ? (($this->deferred - ($Q6 - $this->installments)) * null) + (null > 0.1 ? null : 0) : $settlements[$Q6] + (null * $this->installments);
        }

        protected function getRebateSettlementProportion()
        {
            return $this->getRebateSettlementCalculation() * ($this->getIRR() / $this->deferment * static::SETTLEMENT_DAYS);
        }

        public function getRebateSettlementBalance()
        {
            return ($this->principal + $this->interest) - array_sum(array_slice($this->getPayments(), 0, $this->period));
        }

        protected function getRebateSettlement()
        {
            $calculation = $this->getRebateSettlementCalculation();
            $proportion = $this->getRebateSettlementProportion();
            $balance = $this->getRebateSettlementBalance();

            return $calculation + $proportion >= $balance ? $balance : $calculation + $proportion;
        }

        public function getRebate()
        {
            return $this->installments > $this->period && ($settlement = $this->getRebateSettlement()) <= ($balance = $this->getRebateSettlementBalance()) ? $balance - $settlement : 0;
        }

        public function getCustomerSettlement()
        {
            return $this->getRebateSettlementBalance() - $this->getRebate();
        }

    }
