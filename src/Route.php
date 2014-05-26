<?php namespace Brain\Cortex;

use Symfony\Component\Routing\Route as SymfonyRoute;
use \Brain\Cortex\Controllers\RouterInterface;

/**
 * Concrete implementation for QueryRouteInterface > FrontendRouteInterface > RouteInterface
 *
 * @author Giuseppe Mazzapica
 * @package Brain\Cortex
 */
class Route implements QueryRouteInterface {

    use \Brain\Contextable,
        \Brain\Idable;

    /**
     * Route id
     * @var string
     */
    protected $id;

    /**
     * Propery container fo the route
     * @var \ArrayObject
     */
    protected $context;

    /**
     * Inner Symfony route object
     * @var \Symfony\Component\Routing\Route
     */
    protected $inner_symfony_route;

    /**
     * Router instance
     * @var \Brain\Cortex\Controllers\RouterInterface
     */
    private $router;

    /**
     * Constructor
     *
     * @param \Symfony\Component\Routing\Route $route
     */
    function __construct( SymfonyRoute $route ) {
        $this->inner_symfony_route = $route;
        $this->context = new \ArrayObject;
    }

    /**
     * Magic __call method used to call set/get for arbitrary settings.
     *
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    function __call( $name, $arguments ) {
        if ( strpos( $name, 'get' ) === 0 || strpos( $name, 'set' ) === 0 ) {
            $callback = strpos( $name, 'get' ) === 0 ? 'get' : 'set';
            array_unshift( $arguments, strtolower( substr( $name, 3 ) ) );
            return call_user_func_array( $callback, $arguments );
        } else {
            $aliases = [
                'defaults', 'requirements', 'host', 'methods', 'schemes',
                'priority', 'path', 'query', 'template', 'after', 'before'
            ];
            if ( in_array( strtolower( $name ), $aliases, TRUE ) ) {
                $callback = "set" . ucfirst( $name );
                return call_user_func_array( [ $this, $callback ], $arguments );
            }
            throw new \BadMethodCallException;
        }
    }

    function setRouter( RouterInterface $router = NULL ) {
        $this->router = $router;
        return $this;
    }

    function getRouter() {
        return $this->router;
    }

    function add() {
        if ( $this->getRouter() instanceof RouterInterface ) {
            $this->getRouter()->addRoute( $this );
            $this->setRouter();
            return $this;
        }
    }

    /**
     * Get the underlying Symfony route object
     *
     * @return \Symfony\Component\Routing\Route
     */
    public function getInner() {
        return $this->inner_symfony_route;
    }

    public function get( $index = NULL ) {
        return $this->getContext( 'context', $index );
    }

    public function set( $index = NULL, $value = NULL ) {
        return $this->setContext( 'context', $index, $value );
    }

    public function runAfter() {
        return $this->callback( 'after', func_get_args() );
    }

    public function runBefore() {
        return $this->callback( 'before', func_get_args() );
    }

    public function bindTo( $bind = '' ) {
        if ( ! is_string( $bind ) || empty( $bind ) ) {
            throw new \InvalidArgumentException;
        }
        return $this->set( 'bind_to', $bind );
    }

    /**
     * Bind the route to ClosureRoutable routable and set the closure obecjt to be ran.
     *
     * @param \Closure $closure
     * @return \Brain\Cortex\Route Self
     * @see \Brain\Cortex\Controllers\ClosureRoutable
     * @see \Brain\Cortex\Route::bindTo()
     */
    public function bindToClosure( \Closure $closure ) {
        return $this->bindTo( 'cortex.closure_routable' )->set( 'binded_closure', $closure );
    }

    /**
     * Bind the route to as ActionRoutable routable implementation.
     * Optionally set the variable name to be used.
     *
     * @param \Closure $closure
     * @return  \Brain\Cortex\Route Self
     * @see \Brain\Cortex\Controllers\ActionRoutable
     * @see \Brain\Cortex\Route::bindTo()
     */
    public function bindToAction( $routable = NULL, $var_name = NULL ) {
        if ( is_string( $var_name ) && $var_name !== '' ) {
            $this->set( 'action_routable_id', $var_name );
        }
        if ( $routable instanceof Controllers\ActionRoutable ) {
            return $this->setRoutable( $routable );
        } elseif ( is_string( $routable ) ) {
            return $this->bindTo( $routable );
        }
    }

    /**
     * Bind the route to an arbitrary object method.
     *
     * Can be used to run static or dynamic methods. Method instanciate object when a class name is
     * given and $static param is false (default), however no arguments or other construct routines
     * can be automatically ran. For advanced controllers booting using bindToClosure that is also
     * used internally by this method.
     *
     * @param \Closure $closure
     * @return  \Brain\Cortex\Route Self
     * @see \Brain\Cortex\Route::bindToClosure()
     * @throws \InvalidArgumentException
     */
    public function bindToMethod( $ctrl = NULL, $method = NULL, $static = FALSE ) {
        if ( ! is_string( $method ) ) {
            throw new \InvalidArgumentException;
        }
        if ( is_string( $ctrl ) && ! class_exists( $ctrl ) ) {
            throw new \InvalidArgumentException;
        }
        if ( ( is_object( $ctrl ) || is_string( $ctrl ) ) && method_exists( $ctrl, $method ) ) {
            $closure = function( $matches, $route, $request ) use( $ctrl, $method, $static ) {
                $object = is_string( $ctrl ) && ! $static ? new $ctrl : $ctrl;
                return call_user_func( [ $object, $method ], $matches, $route, $request );
            };
            return $this->bindToClosure( $closure );
        }
    }

    public function getBinding() {
        return $this->get( 'bind_to' );
    }

    public function getRoutable() {
        return $this->get( 'routable' );
    }

    public function getPath() {
        return $this->get( 'path' );
    }

    public function setRoutable( Controllers\RoutableInterface $routable ) {
        return $this->set( 'routable', $routable );
    }

    /**
     * Set dafaults array to be used for defaults argument in Symfony route object
     *
     * @param array $defaults
     * @return \Brain\Cortex\Route Self
     */
    public function setDefaults( Array $defaults = [ ] ) {
        return $this->set( 'defaults', $this->stringKeyed( $defaults ) );
    }

    /**
     * Set host to be used for host argument in Symfony route object
     *
     * @param string $host
     * @return \Brain\Cortex\Route Self
     */
    public function setHost( $host = '' ) {
        $host = filter_var( $host, FILTER_SANITIZE_URL );
        if ( ! is_string( $host ) || empty( $host ) ) {
            throw new \InvalidArgumentException;
        }
        $this->set( 'host', $host );
    }

    /**
     * Set methods array to be used for methods argument in Symfony route object
     *
     * @param array $methods
     * @return \Brain\Cortex\Route Self
     */
    public function setMethods( Array $methods = [ ] ) {
        return $this->set( 'methods', array_values( $methods ) );
    }

    public function setPath( $path = '', Array $requirements = [ ] ) {
        $path = filter_var( $path, FILTER_SANITIZE_URL );
        if ( ! is_string( $path ) || empty( $path ) ) {
            throw new \InvalidArgumentException;
        }
        $this->set( 'path', $path );
        if ( ! empty( $requirements ) ) {
            $this->setRequirements( $requirements );
        }
        return $this;
    }

    /**
     * Set requirements for the route, to be used as requirements argument is Symfony route object
     *
     * @param array $requirements
     * @return \Brain\Cortex\Route Self
     */
    public function setRequirements( Array $requirements = [ ] ) {
        return $this->set( 'requirements', $this->stringKeyed( $requirements ) );
    }

    /**
     * Set schemes for the route, to be used as schemes argument is Symfony route object
     *
     * @param array $schemes
     * @return \Brain\Cortex\Route Self
     */
    public function setSchemes( Array $schemes = [ ] ) {
        return $this->set( 'schemes', array_values( $schemes ) );
    }

    /**
     * Set priority for the route. Router with higher priority wins if there are path conflicts.
     *
     * @param int $priority
     * @return \Brain\Cortex\Route Self
     * @throws \InvalidArgumentException
     */
    public function setPriority( $priority = 1 ) {
        if ( ! is_numeric( $priority ) ) {
            throw new \InvalidArgumentException;
        }
        return $this->set( 'priority', (float) $priority );
    }

    /**
     * Set callable to be ran when the route match, after the related routable runs
     *
     * @param callable $callback
     * @return \Brain\Cortex\Route Self
     * @throws \InvalidArgumentException
     */
    public function setAfter( $callback = NULL ) {
        if ( ! is_callable( $callback ) ) {
            throw new \InvalidArgumentException;
        }
        return $this->set( 'after', $callback );
    }

    /**
     * Set callable to be ran when the route match, before the related routable runs
     *
     * @param callable $callback
     * @return \Brain\Cortex\Route Self
     * @throws \InvalidArgumentException
     */
    public function setBefore( $callback = NULL ) {
        if ( ! is_callable( $callback ) ) {
            throw new \InvalidArgumentException;
        }
        return $this->set( 'before', $callback );
    }

    public function prepare() {
        if ( ! $this->check() ) {
            return FALSE;
        }
        $inner = $this->getInner();
        $path = $this->getPath();
        $requirements = $this->getRequirements() ? : [ ];
        $defaults = $this->getDefaults() ? : [ ];
        if ( $this->get( 'paged' ) === TRUE || $this->get( 'paged' ) === 'single' ) {
            $var = $this->get( 'paged' ) === TRUE ? 'paged' : 'page';
            $base = $GLOBALS['wp_rewrite']->pagination_base;
            $path = trailingslashit( $this->getPath() ) . $base . '/{' . $var . '}';
            $requirements = array_merge( $requirements, [ $var => 'd+' ] );
            $defaults = array_merge( $defaults, [ $var => 1 ] );
        }
        $inner->setPath( $path );
        $inner->setRequirements( $requirements );
        $inner->setDefaults( $defaults );
        foreach ( [ 'Host', 'Schemes', 'Methods' ] as $var ) {
            $get = call_user_func( [ $this, "get{$var}" ] );
            if ( is_null( $get ) || ( ! is_scalar( $get ) && ! is_array( $get ) ) ) continue;
            call_user_func( [ $inner, "set{$var}" ], $get );
        }
        return $inner;
    }

    public function getDefaultSettings() {
        return [
            'template'       => '',
            'querycallback'  => NULL, // callable
            'qsmerge'        => TRUE,
            'autocustomvars' => TRUE,
            'customvars'     => [ ],
            'skipvars'       => [ ]
        ];
    }

    public function setQuery( $callback = NULL ) {
        if ( ! is_callable( $callback ) ) {
            throw new \InvalidArgumentException;
        }
        return $this->set( 'querycallback', $callback );
    }

    public function setTemplate( $template = '' ) {
        if ( ! is_string( $template ) || empty( $template ) ) {
            throw new \InvalidArgumentException;
        }
        return $this->set( 'template', $template );
    }

    private function check() {
        $id = $this->getId();
        $path = $this->getPath();
        return ! empty( $id ) && ! empty( $path ) && is_string( $id ) && is_string( $path );
    }

    private function callback( $which = '', $args = [ ] ) {
        if ( ! is_string( $which ) || empty( $which ) ) {
            throw new \InvalidArgumentException;
        }
        $callback = $this->get( $which );
        if ( ! is_callable( $callback ) ) {
            throw new \DomainException;
        }
        return call_user_func_array( $callback, $args );
    }

    private function stringKeyed( Array $array = [ ] ) {
        if ( ! empty( $array ) ) {
            $keys = array_filter( array_keys( $array ), 'is_string' );
            $array = empty( $keys ) ? [ ] : array_intersect_key( $array, array_flip( $keys ) );
        }
        return $array;
    }

}