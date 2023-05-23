<?php
//ahora buscar en el sitio principal 
$search_token_lider = self::search_token_in_lider($token);
if ($search_token_lider == null) {
  wp_redirect(esc_url(home_url('/404')));
  exit;
}
$response_body = wp_remote_retrieve_body($search_token_lider);
$templateHtml = '
<p id="miTemplate">Template html</p>
<ul>
  <li v-for="(item, index) in arrayList" :key="index">{{item}}</li>
</ul>
';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Checkout</title>
  <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>

  <style>
    body {
      background-color: #F6F8FA;
    }

    .bggray {
      background-color: #F6F8FA;
    }
  </style>
</head>

<body>
  <div id="app">

    <div v-show="loader">
      loader....
    </div>
    <?php echo $templateHtml; ?>

  </div>

  <script>
    const app = Vue.createApp({
      data() {
        return {
          arrayList: [
            "la numero 1", "la numero 2"
          ],
          loader: true,
          message: 'Hello Vue!',


        }
      },
      mounted() {
        console.log("entrando a mounted")
        //  const miParrafo = document.getElementById('miTemplate');
        //   miParrafo.textContent = 'Este es el nuevo texto del párrafo.';
        this.loader = false
      }
    })
/*
    app.component('dynamic-html', {
      props: {
        html: {
          type: String,
          required: true
        }
      },
      data() {
        return {
          arrayList: [
            "la numero 1", "la numero 2"
          ],
        }
      },
      template: `codigo html`
    });

*/

    app.mount('#app');
  </script>
</body>

</html>