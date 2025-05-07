<section>
  <center><h1>Welcome to the Rolling Soft Reserve Manager</h1></center>
  <h3>What is "Rolling Soft Reserve"?</h3>
  <p>Rolling soft reserve is an unconventional loot method that incentivizes raiders to consistently join the same raid group by offering bonus points on their soft reserve /roll for returning for multiple consecutive weeks.</p>
  <h3>How does this work?</h3>
  <p>This website pulls data directly from <a href="http://www.softres.it" target="_blank">softres.it</a>'s API and creates an .csv file containing an organized list of raiders' bonuses.  You can choose to download the file send it directly to discord using a webhook.</p>
  <p>If this is not your first raid, after selecting a .csv to use, you will be given the option to "View Current Data".  This will present you with a list of raiders and their reserved items with bonuses.  The list is searchable by raider name or item name.  The idea is to use this during loot distribution to quickly look up everyone who has a reservation on a particular item, as well as the amount of bonus points they may have.  After using the "View Current Data" option, you will have the option to apply bonuses, which will generate the .csv file for your next raid.</p>
</section>
<section>
  <center>
    <h1>Create/Manage Your Rolling Soft Res</h1>
  </center>
  <?php include('softres.php'); ?>
</section>
