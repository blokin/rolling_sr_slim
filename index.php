<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Rolling Soft Reserve Manager</title>
  <link rel="stylesheet" href="style.css?v=<?= time(); ?>">
</head>
<body>

  <header>
    <h1>Rolling Soft Reserve Manager</h1>
    <nav>
      <a href="index.php">Home</a>
      <a href="index.php?id=help">Help</a>
    </nav>
  </header>
  <main>
    <?php
      $id = $_GET['id'] ?? '';
      switch($id) {
        default:
          include('home.php');
          break;
        case "help": include('help.html'); break;
      }
    ?>
  </main>
  <footer>
    Rolling Soft Reserve Manager by <a href="https://www.youtube.com/watch?v=Qm9bUYVx8aI" target="_blank">Blokin</a>.<br>
    Questions?  Problems?  Suggestions?  Come join us on <a href="https://discord.gg/bqGPDKvYbe" target="_blank">Discord</a>!
  </footer>
</body>
</html>
