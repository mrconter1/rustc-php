// error: has no variant
fn main() {
    let opt: Option<i32> = Option::<i32>::Some(1);
    if let Option::<i32>::Foo(n) = opt {
        println!("{}", n);
    }
}
