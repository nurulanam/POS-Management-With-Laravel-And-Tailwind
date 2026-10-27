<input type="hidden" name="branch_id" value="{{ $currentBranch->id }}">
<div class="field"><label for="from">From date</label><input id="from" type="date" name="from" required min="2000-01-01" max="2099-12-31" value="{{ $period->from }}"></div>
<div class="field"><label for="to">Through date</label><input id="to" type="date" name="to" required min="2000-01-01" max="2099-12-31" value="{{ $period->to }}"></div>
