// exit: 0
// stdout: ok
fn main() {
    let opt: Option<i32> = Option::<i32>::None;
    while let Some(_) = opt {
        println!("bad");
    }
    println!("ok");
}
