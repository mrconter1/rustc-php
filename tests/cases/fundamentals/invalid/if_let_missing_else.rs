// error: if let expression requires else

fn main() {
    let opt: Option<i32> = Option::<i32>::Some(1);
    let x = if let Option::<i32>::Some(n) = opt {
        n
    };
    println!("{}", x);
}
