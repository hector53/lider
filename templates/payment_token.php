<?php
//ahora buscar en el sitio principal 
$search_token_lider = self::search_token_in_lider($token);
if ($search_token_lider == null) {
  wp_redirect(esc_url(home_url('/404')));
  exit;
}
$response_body = wp_remote_retrieve_body($search_token_lider);
$decodeBody = json_decode($response_body);
$templateHtml = $decodeBody->template;

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Checkout</title>
  <!-- Incluimos Handlebars con un CDN -->
  <script src="https://cdn.jsdelivr.net/npm/handlebars/dist/handlebars.min.js"></script>
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

    <div v-show="!loader" v-html="html">

    </div>

  </div>

  <script>
    const source = `<?php echo $templateHtml; ?>`;
    Handlebars.registerHelper("isMayor", function(a, b, options) {
      if (a > b) {
        //@ts-ignore
        return options.fn(this);
      } else {
        //@ts-ignore
        return options.inverse(this);
      }
    });

    Handlebars.registerHelper("isIgual", function(a, b, options) {
      if (a === b) {
        //@ts-ignore
        return options.fn(this);
      } else {
        //@ts-ignore
        return options.inverse(this);
      }
    });
    const template = Handlebars.compile(source);
    const data = <?php echo $response_body; ?>;
    const htmlHandle = template(data);
    const app = Vue.createApp({
      data() {
        return {
          arrayList: [
            "la numero 1", "la numero 2"
          ],
          loader: true,
          message: 'Hello Vue!',
          html: htmlHandle
        }
      },
      mounted() {
   //     console.log("entrando a mounted", this.html)
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
          template: htmlHandle
        });*/


    app.mount('#app');
  </script>
</body>

</html>